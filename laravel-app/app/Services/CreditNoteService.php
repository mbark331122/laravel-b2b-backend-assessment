<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Refund;
use App\Models\ReturnShipment;
use App\Models\Rma;
use App\Models\RmaItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreditNoteService
{
    /**
     * Create a draft credit note from a closed RMA with a delivered return shipment (idempotent).
     *
     * @return array{credit_note: CreditNote, created: bool}
     */
    public function createFromClosedRma(Rma $rma, User $actor, ?string $reason = null): array
    {
        return DB::transaction(function () use ($rma, $actor, $reason) {
            /** @var Rma $locked */
            $locked = Rma::query()->whereKey($rma->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing(['items', 'shipment', 'purchaseOrder']);

            if ($locked->status !== Rma::STATUS_CLOSED) {
                throw ValidationException::withMessages([
                    'rma' => 'Credit notes can only be created for a closed RMA.',
                ]);
            }

            if ((int) $actor->company_id !== (int) $locked->supplier_company_id) {
                throw ValidationException::withMessages([
                    'rma' => 'Only the supplier company may create a credit note for this RMA.',
                ]);
            }

            $deliveredReturn = ReturnShipment::query()
                ->where('rma_id', $locked->id)
                ->where('status', ReturnShipment::STATUS_DELIVERED)
                ->lockForUpdate()
                ->first();

            if (! $deliveredReturn) {
                throw ValidationException::withMessages([
                    'rma' => 'Credit notes require a delivered return shipment for this RMA.',
                ]);
            }

            if ((int) $deliveredReturn->shipment_id !== (int) $locked->shipment_id) {
                throw ValidationException::withMessages([
                    'rma' => 'Return shipment must belong to the same original shipment as the RMA.',
                ]);
            }

            if ((int) $locked->shipment?->purchase_order_id !== (int) $locked->purchase_order_id) {
                throw ValidationException::withMessages([
                    'rma' => 'RMA shipment is not linked to the same purchase order.',
                ]);
            }

            /** @var Invoice|null $invoice */
            $invoice = Invoice::query()
                ->where('purchase_order_id', $locked->purchase_order_id)
                ->lockForUpdate()
                ->first();

            if (! $invoice) {
                throw ValidationException::withMessages([
                    'invoice' => 'No invoice exists for the RMA purchase order.',
                ]);
            }

            if ((int) $invoice->buyer_company_id !== (int) $locked->buyer_company_id
                || (int) $invoice->supplier_company_id !== (int) $locked->supplier_company_id
                || (int) $invoice->purchase_order_id !== (int) $locked->purchase_order_id) {
                throw ValidationException::withMessages([
                    'invoice' => 'Invoice does not belong to the same commercial chain as the RMA.',
                ]);
            }

            $existing = CreditNote::query()
                ->where('rma_id', $locked->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return [
                    'credit_note' => $existing->fresh([
                        'items',
                        'rma',
                        'invoice',
                        'purchaseOrder',
                        'buyerCompany',
                        'supplierCompany.supplierProfile',
                    ]),
                    'created' => false,
                ];
            }

            $invoice->loadMissing('items');

            $creditNote = new CreditNote;
            $creditNote->rma()->associate($locked);
            $creditNote->invoice()->associate($invoice);
            $creditNote->purchase_order_id = $locked->purchase_order_id;
            $creditNote->buyer_company_id = $locked->buyer_company_id;
            $creditNote->supplier_company_id = $locked->supplier_company_id;
            $creditNote->status = CreditNote::STATUS_DRAFT;
            $creditNote->currency = $invoice->currency;
            $creditNote->reason = $this->resolveReason($locked, $reason);
            $creditNote->number = 'PENDING-'.$locked->id.'-'.uniqid('', true);
            $creditNote->subtotal = '0.00';
            $creditNote->tax_amount = '0.00';
            $creditNote->total = '0.00';
            $creditNote->save();

            $creditNote->number = sprintf('CN-%d-%06d', (int) $creditNote->created_at->year, (int) $creditNote->id);
            $creditNote->save();

            $subtotal = '0.00';
            foreach ($locked->items as $index => $rmaItem) {
                $lineTotal = $this->snapshotItem($creditNote, $rmaItem, $invoice, $index);
                $subtotal = bcadd($subtotal, $lineTotal, 2);
            }

            $taxAmount = $this->proportionalTax($invoice, $subtotal);
            $total = bcadd($subtotal, $taxAmount, 2);

            $creditNote->subtotal = $subtotal;
            $creditNote->tax_amount = $taxAmount;
            $creditNote->total = $total;
            $creditNote->save();

            if (bccomp($total, '0.00', 2) <= 0) {
                throw ValidationException::withMessages([
                    'credit_note' => 'Credit note total must be greater than zero.',
                ]);
            }

            if (bccomp($total, (string) $invoice->total, 2) > 0) {
                throw ValidationException::withMessages([
                    'credit_note' => 'Credit note total cannot exceed the original invoice total.',
                ]);
            }

            return [
                'credit_note' => $creditNote->fresh([
                    'items',
                    'rma',
                    'invoice',
                    'purchaseOrder',
                    'buyerCompany',
                    'supplierCompany.supplierProfile',
                ]),
                'created' => true,
            ];
        });
    }

    public function issue(CreditNote $creditNote): CreditNote
    {
        return $this->transition($creditNote, CreditNote::STATUS_ISSUED);
    }

    public function cancel(CreditNote $creditNote, ?string $reason = null): CreditNote
    {
        return DB::transaction(function () use ($creditNote, $reason) {
            /** @var CreditNote $locked */
            $locked = CreditNote::query()->whereKey($creditNote->id)->lockForUpdate()->firstOrFail();
            if ($reason !== null && trim($reason) !== '') {
                $locked->cancellation_reason = trim($reason);
            }

            try {
                $locked->transitionTo(CreditNote::STATUS_CANCELLED);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'credit_note' => $exception->getMessage(),
                ]);
            }

            return $locked->fresh([
                'items',
                'rma',
                'invoice',
                'purchaseOrder',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ]);
        });
    }

    public function void(CreditNote $creditNote, string $reason): CreditNote
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A void reason is required.',
            ]);
        }

        return DB::transaction(function () use ($creditNote, $reason) {
            /** @var CreditNote $locked */
            $locked = CreditNote::query()->whereKey($creditNote->id)->lockForUpdate()->firstOrFail();

            $refundExists = Refund::query()
                ->where('credit_note_id', $locked->id)
                ->lockForUpdate()
                ->exists();

            if ($refundExists) {
                throw ValidationException::withMessages([
                    'credit_note' => 'An issued credit note with a refund record cannot be voided.',
                ]);
            }

            $locked->void_reason = $reason;

            try {
                $locked->transitionTo(CreditNote::STATUS_VOIDED);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'credit_note' => $exception->getMessage(),
                ]);
            }

            return $locked->fresh([
                'items',
                'rma',
                'invoice',
                'purchaseOrder',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ]);
        });
    }

    private function transition(CreditNote $creditNote, string $to): CreditNote
    {
        return DB::transaction(function () use ($creditNote, $to) {
            /** @var CreditNote $locked */
            $locked = CreditNote::query()->whereKey($creditNote->id)->lockForUpdate()->firstOrFail();

            try {
                $locked->transitionTo($to);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'credit_note' => $exception->getMessage(),
                ]);
            }

            return $locked->fresh([
                'items',
                'rma',
                'invoice',
                'purchaseOrder',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ]);
        });
    }

    private function snapshotItem(
        CreditNote $creditNote,
        RmaItem $rmaItem,
        Invoice $invoice,
        int $sortOrder,
    ): string {
        /** @var InvoiceItem|null $invoiceItem */
        $invoiceItem = $invoice->items->first(
            fn (InvoiceItem $item) => (int) $item->purchase_order_item_id === (int) $rmaItem->purchase_order_item_id
        );

        if (! $invoiceItem) {
            throw ValidationException::withMessages([
                'rma' => 'RMA item does not map to an invoice line on the same purchase order.',
            ]);
        }

        if ((int) $rmaItem->quantity > (int) $invoiceItem->quantity) {
            throw ValidationException::withMessages([
                'rma' => 'RMA quantity cannot exceed the original invoice item quantity.',
            ]);
        }

        $unitPrice = (string) $invoiceItem->unit_price;
        $lineTotal = bcmul($unitPrice, (string) $rmaItem->quantity, 2);

        $row = new CreditNoteItem;
        $row->creditNote()->associate($creditNote);
        $row->rma_item_id = $rmaItem->id;
        $row->invoice_item_id = $invoiceItem->id;
        $row->product_id = $rmaItem->product_id ?? $invoiceItem->product_id;
        $row->description = $rmaItem->description ?? $invoiceItem->description;
        $row->quantity = $rmaItem->quantity;
        $row->unit = $rmaItem->unit ?? $invoiceItem->unit;
        $row->unit_price_snapshot = $unitPrice;
        $row->line_total_snapshot = $lineTotal;
        $row->currency = $invoice->currency;
        $row->product_snapshot = [
            'source' => 'invoice_item_and_rma_item',
            'captured_at' => now()->toISOString(),
            'rma_item' => $rmaItem->toApiArray(),
            'invoice_item' => $invoiceItem->toApiArray(),
            'product_snapshot' => $rmaItem->product_snapshot ?? $invoiceItem->product_snapshot,
        ];
        $row->sort_order = $sortOrder;
        $row->save();

        return $lineTotal;
    }

    private function proportionalTax(Invoice $invoice, string $creditSubtotal): string
    {
        $invoiceSubtotal = (string) $invoice->subtotal;
        if (bccomp($invoiceSubtotal, '0.00', 2) <= 0) {
            return '0.00';
        }

        $invoiceTax = (string) $invoice->tax_amount;
        if (bccomp($invoiceTax, '0.00', 2) <= 0) {
            return '0.00';
        }

        return bcdiv(bcmul($invoiceTax, $creditSubtotal, 4), $invoiceSubtotal, 2);
    }

    private function resolveReason(Rma $rma, ?string $reason): string
    {
        if ($reason !== null && trim($reason) !== '') {
            return trim($reason);
        }

        return (string) $rma->reason;
    }
}
