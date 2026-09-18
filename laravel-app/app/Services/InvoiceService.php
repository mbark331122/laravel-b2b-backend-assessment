<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class InvoiceService
{
    /**
     * Create an invoice from a confirmed PO (idempotent).
     *
     * @return array{invoice: Invoice, created: bool}
     */
    public function createFromConfirmedPurchaseOrder(PurchaseOrder $purchaseOrder, User $actor): array
    {
        return DB::transaction(function () use ($purchaseOrder, $actor) {
            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::query()->whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing(['items', 'buyerCompany', 'supplierCompany.supplierProfile']);

            if ($locked->status !== PurchaseOrder::STATUS_CONFIRMED) {
                throw ValidationException::withMessages([
                    'purchase_order' => 'Invoices can only be created from a confirmed purchase order.',
                ]);
            }

            if ((int) $actor->company_id !== (int) $locked->supplier_company_id) {
                throw ValidationException::withMessages([
                    'purchase_order' => 'Only the supplier company may create an invoice for this purchase order.',
                ]);
            }

            $existing = Invoice::query()
                ->where('purchase_order_id', $locked->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return [
                    'invoice' => $existing->fresh([
                        'items',
                        'buyerCompany',
                        'supplierCompany.supplierProfile',
                    ]),
                    'created' => false,
                ];
            }

            $invoice = new Invoice;
            $invoice->purchaseOrder()->associate($locked);
            $invoice->purchase_order_number = $locked->number;
            $invoice->buyer_company_id = $locked->buyer_company_id;
            $invoice->supplier_company_id = $locked->supplier_company_id;
            $invoice->status = Invoice::STATUS_DRAFT;
            $invoice->currency = $locked->currency;
            $invoice->shipping_amount = $locked->shipping_amount;
            $invoice->tax_amount = $locked->tax_amount;
            $invoice->subtotal = $locked->subtotal;
            $invoice->total = $locked->total;
            $invoice->notes = $locked->notes;
            $invoice->buyer_snapshot = [
                'id' => $locked->buyer_company_id,
                'name' => $locked->buyerCompany?->name,
                'captured_at' => now()->toISOString(),
            ];
            $invoice->supplier_snapshot = [
                'id' => $locked->supplier_company_id,
                'name' => $locked->supplierCompany?->name,
                'display_name' => $locked->supplierCompany?->supplierProfile?->display_name,
                'captured_at' => now()->toISOString(),
            ];
            $invoice->number = 'PENDING-'.$locked->id.'-'.uniqid('', true);
            $invoice->save();

            $invoice->number = sprintf('INV-%d-%06d', (int) $invoice->created_at->year, (int) $invoice->id);
            $invoice->save();

            foreach ($locked->items as $index => $item) {
                $this->snapshotItem($invoice, $item, $locked->currency, $index);
            }

            $this->assertMatchesPurchaseOrder($invoice, $locked);

            return [
                'invoice' => $invoice->fresh([
                    'items',
                    'buyerCompany',
                    'supplierCompany.supplierProfile',
                ]),
                'created' => true,
            ];
        });
    }

    public function issue(Invoice $invoice): Invoice
    {
        return $this->transition($invoice, Invoice::STATUS_ISSUED);
    }

    public function cancel(Invoice $invoice, ?string $reason = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason) {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($reason !== null && trim($reason) !== '') {
                $locked->cancellation_reason = trim($reason);
            }

            try {
                $locked->transitionTo(Invoice::STATUS_CANCELLED);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'invoice' => $exception->getMessage(),
                ]);
            }

            return $locked->fresh([
                'items',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ]);
        });
    }

    public function void(Invoice $invoice, string $reason): Invoice
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A void reason is required.',
            ]);
        }

        return DB::transaction(function () use ($invoice, $reason) {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $blockingPayment = Payment::query()
                ->where('invoice_id', $locked->id)
                ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_PAID])
                ->lockForUpdate()
                ->exists();

            if ($blockingPayment) {
                throw ValidationException::withMessages([
                    'invoice' => 'An invoice with a pending or paid payment cannot be voided.',
                ]);
            }

            $locked->void_reason = $reason;

            try {
                $locked->transitionTo(Invoice::STATUS_VOIDED);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'invoice' => $exception->getMessage(),
                ]);
            }

            return $locked->fresh([
                'items',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ]);
        });
    }

    private function transition(Invoice $invoice, string $to): Invoice
    {
        return DB::transaction(function () use ($invoice, $to) {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            try {
                $locked->transitionTo($to);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'invoice' => $exception->getMessage(),
                ]);
            }

            return $locked->fresh([
                'items',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ]);
        });
    }

    private function snapshotItem(
        Invoice $invoice,
        PurchaseOrderItem $item,
        string $currency,
        int $sortOrder,
    ): void {
        $row = new InvoiceItem;
        $row->invoice()->associate($invoice);
        $row->purchase_order_item_id = $item->id;
        $row->product_id = $item->product_id;
        $row->description = $item->description;
        $row->quantity = $item->quantity;
        $row->unit = $item->unit;
        $row->unit_price = $item->unit_price;
        $row->line_total = $item->line_total;
        $row->currency = $currency;
        $row->notes = $item->notes;
        $row->product_snapshot = [
            'source' => 'confirmed_purchase_order_item',
            'captured_at' => now()->toISOString(),
            'purchase_order_item' => $item->toApiArray(),
            'product_snapshot' => $item->product_snapshot,
        ];
        $row->sort_order = $sortOrder;
        $row->save();
    }

    private function assertMatchesPurchaseOrder(Invoice $invoice, PurchaseOrder $po): void
    {
        $invoice->loadMissing('items');

        if ((string) $invoice->subtotal !== (string) $po->subtotal
            || (string) $invoice->shipping_amount !== (string) $po->shipping_amount
            || (string) $invoice->tax_amount !== (string) $po->tax_amount
            || (string) $invoice->total !== (string) $po->total
            || $invoice->currency !== $po->currency) {
            throw ValidationException::withMessages([
                'invoice' => 'Invoice financial snapshot does not match the confirmed purchase order.',
            ]);
        }

        if ($invoice->items->count() !== $po->items->count()) {
            throw ValidationException::withMessages([
                'invoice' => 'Invoice items do not match the confirmed purchase order.',
            ]);
        }
    }
}
