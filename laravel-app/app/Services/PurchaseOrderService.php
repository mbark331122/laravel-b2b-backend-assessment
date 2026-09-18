<?php

namespace App\Services;

use App\Models\Negotiation;
use App\Models\NegotiationOfferItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PurchaseOrderService
{
    /**
     * Create a PO from an accepted negotiation (idempotent: returns existing if present).
     *
     * @return array{purchase_order: PurchaseOrder, created: bool}
     */
    public function createFromAcceptedNegotiation(Negotiation $negotiation, User $actor): array
    {
        return DB::transaction(function () use ($negotiation, $actor) {
            /** @var Negotiation $locked */
            $locked = Negotiation::query()->whereKey($negotiation->id)->lockForUpdate()->firstOrFail();
            $locked->refreshExpiration();

            if ($locked->status !== Negotiation::STATUS_ACCEPTED) {
                throw ValidationException::withMessages([
                    'negotiation' => 'Purchase orders can only be created from an accepted negotiation.',
                ]);
            }

            if ($locked->accepted_offer_id === null) {
                throw ValidationException::withMessages([
                    'negotiation' => 'Accepted negotiation is missing an accepted offer.',
                ]);
            }

            if ((int) $actor->company_id !== (int) $locked->buyer_company_id) {
                throw ValidationException::withMessages([
                    'negotiation' => 'Only the buyer company may create a purchase order.',
                ]);
            }

            $existing = PurchaseOrder::query()
                ->where('negotiation_id', $locked->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return [
                    'purchase_order' => $existing->fresh([
                        'items',
                        'buyerCompany',
                        'supplierCompany.supplierProfile',
                    ]),
                    'created' => false,
                ];
            }

            $offer = $locked->acceptedOffer()->with('items')->firstOrFail();

            $po = new PurchaseOrder;
            $po->negotiation()->associate($locked);
            $po->accepted_offer_id = $offer->id;
            $po->quotation_id = $locked->quotation_id;
            $po->rfq_id = $locked->rfq_id;
            $po->rfq_distribution_id = $locked->rfq_distribution_id;
            $po->buyer_company_id = $locked->buyer_company_id;
            $po->supplier_company_id = $locked->supplier_company_id;
            $po->status = PurchaseOrder::STATUS_DRAFT;
            $po->currency = $offer->currency;
            $po->shipping_amount = $offer->shipping_amount;
            $po->tax_amount = $offer->tax_amount;
            $po->subtotal = $offer->subtotal;
            $po->total = $offer->total;
            $po->notes = $offer->notes;
            // Temporary unique placeholder; finalized after insert using primary key.
            $po->number = 'PENDING-'.$locked->id.'-'.uniqid('', true);
            $po->save();

            $po->number = $this->formatNumber((int) $po->id, (int) $po->created_at->year);
            $po->save();

            foreach ($offer->items as $index => $item) {
                $this->snapshotItem($po, $item, $offer->currency, $index);
            }

            return [
                'purchase_order' => $po->fresh([
                    'items',
                    'buyerCompany',
                    'supplierCompany.supplierProfile',
                ]),
                'created' => true,
            ];
        });
    }

    public function submit(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        return $this->transition($purchaseOrder, PurchaseOrder::STATUS_PENDING_SUPPLIER_CONFIRMATION, function (PurchaseOrder $po): void {
            if ($po->items()->count() < 1) {
                throw ValidationException::withMessages([
                    'purchase_order' => 'Purchase order must contain at least one item before submission.',
                ]);
            }
        });
    }

    public function confirm(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        return $this->transition($purchaseOrder, PurchaseOrder::STATUS_CONFIRMED);
    }

    public function reject(PurchaseOrder $purchaseOrder, string $reason): PurchaseOrder
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A rejection reason is required.',
            ]);
        }

        return DB::transaction(function () use ($purchaseOrder, $reason) {
            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::query()->whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();
            $locked->rejection_reason = $reason;

            try {
                $locked->transitionTo(PurchaseOrder::STATUS_REJECTED);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'purchase_order' => $exception->getMessage(),
                ]);
            }

            return $locked->fresh([
                'items',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ]);
        });
    }

    public function cancel(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        return $this->transition($purchaseOrder, PurchaseOrder::STATUS_CANCELLED);
    }

    public function complete(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        return $this->transition($purchaseOrder, PurchaseOrder::STATUS_COMPLETED);
    }

    /**
     * @param  callable(PurchaseOrder): void|null  $before
     */
    private function transition(PurchaseOrder $purchaseOrder, string $to, ?callable $before = null): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrder, $to, $before) {
            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::query()->whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();

            if ($before) {
                $before($locked);
            }

            try {
                $locked->transitionTo($to);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'purchase_order' => $exception->getMessage(),
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
        PurchaseOrder $po,
        NegotiationOfferItem $item,
        string $currency,
        int $sortOrder,
    ): void {
        $row = new PurchaseOrderItem;
        $row->purchaseOrder()->associate($po);
        $row->rfq_item_id = $item->rfq_item_id;
        $row->product_id = $item->product_id;
        $row->description = $item->description;
        $row->quantity = $item->quantity;
        $row->unit = $item->unit;
        $row->unit_price = $item->unit_price;
        $row->line_total = $item->line_total;
        $row->currency = $currency;
        $row->notes = $item->notes;
        $row->product_snapshot = [
            'source' => 'accepted_negotiation_offer',
            'captured_at' => now()->toISOString(),
            'offer_item' => $item->toApiArray(),
            'product_snapshot' => $item->product_snapshot,
        ];
        $row->sort_order = $sortOrder;
        $row->save();
    }

    private function formatNumber(int $id, int $year): string
    {
        return sprintf('PO-%d-%06d', $year, $id);
    }
}
