<?php

namespace App\Services;

use App\Models\Negotiation;
use App\Models\NegotiationOffer;
use App\Models\NegotiationOfferItem;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\RfqItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class NegotiationService
{
    /**
     * Open a negotiation against an active submitted quotation and seed offer #1 from it.
     *
     * @param  array<string, mixed>  $payload
     */
    public function open(Quotation $quotation, User $actor, array $payload = []): Negotiation
    {
        $quotation->refreshExpiration();

        if (! $quotation->isActiveOffer()) {
            throw ValidationException::withMessages([
                'quotation' => 'Only an active submitted quotation can open a negotiation.',
            ]);
        }

        $quotation->loadMissing(['rfq', 'distribution', 'items', 'supplierCompany']);

        $side = $this->actorSideForQuotation($quotation, $actor);
        if ($side === null) {
            throw ValidationException::withMessages([
                'quotation' => 'Only the buyer or supplier party may open this negotiation.',
            ]);
        }

        return DB::transaction(function () use ($quotation, $actor, $payload) {
            // Lock quotation row to serialize concurrent open attempts.
            Quotation::query()->whereKey($quotation->id)->lockForUpdate()->first();

            $accepted = Negotiation::query()
                ->where('quotation_id', $quotation->id)
                ->where('status', Negotiation::STATUS_ACCEPTED)
                ->lockForUpdate()
                ->exists();

            if ($accepted) {
                throw ValidationException::withMessages([
                    'quotation' => 'An accepted negotiation already exists for this quotation.',
                ]);
            }

            $existing = Negotiation::query()
                ->where('quotation_id', $quotation->id)
                ->where('status', Negotiation::STATUS_OPEN)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $existing->refreshExpiration();
                if ($existing->status === Negotiation::STATUS_OPEN) {
                    throw ValidationException::withMessages([
                        'quotation' => 'An open negotiation already exists for this quotation.',
                    ]);
                }
            }

            $negotiation = new Negotiation;
            $negotiation->rfq()->associate($quotation->rfq);
            $negotiation->distribution()->associate($quotation->distribution);
            $negotiation->quotation()->associate($quotation);
            $negotiation->buyer_company_id = $quotation->rfq->company_id;
            $negotiation->supplier_company_id = $quotation->supplier_company_id;
            $negotiation->status = Negotiation::STATUS_OPEN;
            $negotiation->valid_until = $payload['valid_until'] ?? $quotation->valid_until?->format('Y-m-d');
            $negotiation->active_lock = $quotation->id;
            $negotiation->save();

            $this->createOfferFromQuotation($negotiation, $quotation, $actor);

            return $negotiation->fresh([
                'offers.items',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ]);
        });
    }

    /**
     * Append an immutable counter-offer (append-only history).
     *
     * @param  array<string, mixed>  $payload
     */
    public function createCounterOffer(Negotiation $negotiation, User $actor, array $payload): NegotiationOffer
    {
        return DB::transaction(function () use ($negotiation, $actor, $payload) {
            /** @var Negotiation $locked */
            $locked = Negotiation::query()->whereKey($negotiation->id)->lockForUpdate()->firstOrFail();
            $locked->refreshExpiration();

            if ($locked->status !== Negotiation::STATUS_OPEN) {
                throw ValidationException::withMessages([
                    'negotiation' => 'Only open negotiations can receive counter-offers.',
                ]);
            }

            $side = $locked->participantSide($actor);
            if ($side === null) {
                throw ValidationException::withMessages([
                    'negotiation' => 'Only negotiation participants may create offers.',
                ]);
            }

            $expected = $locked->nextTurnSide();
            if ($side !== $expected) {
                throw ValidationException::withMessages([
                    'negotiation' => 'It is not your turn to create a counter-offer.',
                ]);
            }

            $latest = $locked->offers()->reorder()->orderByDesc('sequence')->lockForUpdate()->first();
            if ($latest && $latest->side === $side) {
                throw ValidationException::withMessages([
                    'negotiation' => 'Consecutive offers by the same party are not allowed.',
                ]);
            }

            if ($latest) {
                $latest->status = NegotiationOffer::STATUS_SUPERSEDED;
                $latest->save();
            }

            $sequence = ($latest?->sequence ?? 0) + 1;
            $locked->loadMissing(['rfq', 'quotation']);
            $offer = $this->createOfferFromPayload($locked, $actor, $side, $sequence, $payload);

            return $offer->fresh(['items', 'createdByCompany', 'createdByUser']);
        });
    }

    public function acceptOffer(Negotiation $negotiation, NegotiationOffer $offer, User $actor): Negotiation
    {
        return DB::transaction(function () use ($negotiation, $offer, $actor) {
            /** @var Negotiation $locked */
            $locked = Negotiation::query()->whereKey($negotiation->id)->lockForUpdate()->firstOrFail();
            $locked->refreshExpiration();

            if ($locked->status !== Negotiation::STATUS_OPEN) {
                throw ValidationException::withMessages([
                    'negotiation' => 'Only open negotiations can accept an offer.',
                ]);
            }

            if ((int) $offer->negotiation_id !== (int) $locked->id) {
                throw ValidationException::withMessages([
                    'offer' => 'Offer does not belong to this negotiation.',
                ]);
            }

            /** @var NegotiationOffer $lockedOffer */
            $lockedOffer = NegotiationOffer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();

            $latest = $locked->offers()->reorder()->orderByDesc('sequence')->lockForUpdate()->first();
            if ($latest === null || (int) $latest->id !== (int) $lockedOffer->id) {
                throw ValidationException::withMessages([
                    'offer' => 'Only the latest proposed offer may be accepted.',
                ]);
            }

            if (! $lockedOffer->isAcceptable()) {
                throw ValidationException::withMessages([
                    'offer' => 'Offer is not acceptable (expired or not proposed).',
                ]);
            }

            $side = $locked->participantSide($actor);
            if ($side === null) {
                throw ValidationException::withMessages([
                    'negotiation' => 'Only negotiation participants may accept offers.',
                ]);
            }

            if ($side === $lockedOffer->side) {
                throw ValidationException::withMessages([
                    'offer' => 'Only the opposite party may accept this offer.',
                ]);
            }

            $lockedOffer->status = NegotiationOffer::STATUS_ACCEPTED;
            $lockedOffer->save();

            $locked->accepted_offer_id = $lockedOffer->id;
            $locked->accepted_by_company_id = $actor->company_id;
            $locked->accepted_at = now();

            try {
                $locked->transitionTo(Negotiation::STATUS_ACCEPTED);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'negotiation' => $exception->getMessage(),
                ]);
            }

            // Original quotation remains unchanged historical document.
            return $locked->fresh([
                'offers.items',
                'acceptedOffer.items',
                'buyerCompany',
                'supplierCompany.supplierProfile',
                'acceptedByCompany',
            ]);
        });
    }

    public function reject(Negotiation $negotiation, User $actor): Negotiation
    {
        return $this->close($negotiation, $actor, Negotiation::STATUS_REJECTED);
    }

    public function withdraw(Negotiation $negotiation, User $actor): Negotiation
    {
        return $this->close($negotiation, $actor, Negotiation::STATUS_WITHDRAWN);
    }

    private function close(Negotiation $negotiation, User $actor, string $status): Negotiation
    {
        return DB::transaction(function () use ($negotiation, $actor, $status) {
            /** @var Negotiation $locked */
            $locked = Negotiation::query()->whereKey($negotiation->id)->lockForUpdate()->firstOrFail();
            $locked->refreshExpiration();

            if ($locked->participantSide($actor) === null && ! $actor->isAdmin()) {
                throw ValidationException::withMessages([
                    'negotiation' => 'Only negotiation participants may close the negotiation.',
                ]);
            }

            try {
                $locked->transitionTo($status);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'negotiation' => $exception->getMessage(),
                ]);
            }

            return $locked->fresh([
                'offers.items',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ]);
        });
    }

    private function createOfferFromQuotation(Negotiation $negotiation, Quotation $quotation, User $actor): NegotiationOffer
    {
        $offer = new NegotiationOffer;
        $offer->negotiation()->associate($negotiation);
        $offer->sequence = 1;
        $offer->created_by_company_id = $quotation->supplier_company_id;
        $offer->created_by_user_id = $actor->id;
        $offer->side = NegotiationOffer::SIDE_SUPPLIER;
        $offer->status = NegotiationOffer::STATUS_PROPOSED;
        $offer->currency = $quotation->currency;
        $offer->valid_until = $quotation->valid_until;
        $offer->notes = $quotation->notes;
        $offer->shipping_amount = $quotation->shipping_amount;
        $offer->tax_amount = $quotation->tax_amount;
        $offer->subtotal = $quotation->subtotal;
        $offer->total = $quotation->total;
        $offer->save();

        foreach ($quotation->items as $index => $item) {
            $this->copyQuotationItem($offer, $item, $index);
        }

        return $offer;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createOfferFromPayload(
        Negotiation $negotiation,
        User $actor,
        string $side,
        int $sequence,
        array $payload,
    ): NegotiationOffer {
        $itemsPayload = $payload['items'] ?? null;
        if (! is_array($itemsPayload) || $itemsPayload === []) {
            throw ValidationException::withMessages([
                'items' => 'At least one offer item is required.',
            ]);
        }

        $offer = new NegotiationOffer;
        $offer->negotiation()->associate($negotiation);
        $offer->sequence = $sequence;
        $offer->created_by_company_id = $actor->company_id;
        $offer->created_by_user_id = $actor->id;
        $offer->side = $side;
        $offer->status = NegotiationOffer::STATUS_PROPOSED;
        $offer->currency = strtoupper((string) ($payload['currency'] ?? $negotiation->quotation?->currency ?? 'USD'));
        $offer->valid_until = $payload['valid_until'] ?? null;
        $offer->notes = $payload['notes'] ?? null;
        $offer->shipping_amount = $this->money($payload['shipping_amount'] ?? 0);
        $offer->tax_amount = $this->money($payload['tax_amount'] ?? 0);
        $offer->subtotal = '0.00';
        $offer->total = '0.00';
        $offer->save();

        $rfqItemIds = $negotiation->rfq->items()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();
        $quoted = collect();

        foreach ($itemsPayload as $index => $itemPayload) {
            $rfqItem = $this->resolveRfqItem($negotiation, $itemPayload['rfq_item_id'] ?? null);
            if ($quoted->contains($rfqItem->id)) {
                throw ValidationException::withMessages([
                    'items' => 'Duplicate RFQ item in offer.',
                ]);
            }
            $quoted->push($rfqItem->id);
            $this->createOfferItem($offer, $rfqItem, $itemPayload, $index);
        }

        if ($rfqItemIds->all() !== $quoted->sort()->values()->all()) {
            throw ValidationException::withMessages([
                'items' => 'Offer must include every RFQ item.',
            ]);
        }

        $this->recalculateOfferTotals($offer);

        return $offer;
    }

    private function copyQuotationItem(NegotiationOffer $offer, QuotationItem $item, int $sortOrder): void
    {
        $row = new NegotiationOfferItem;
        $row->offer()->associate($offer);
        $row->rfq_item_id = $item->rfq_item_id;
        $row->product_id = $item->product_id;
        $row->description = $item->description;
        $row->quantity = $item->quantity;
        $row->unit = $item->unit;
        $row->unit_price = $item->unit_price;
        $row->line_total = $item->line_total;
        $row->notes = $item->notes;
        $row->product_snapshot = $item->product_snapshot;
        $row->sort_order = $sortOrder;
        $row->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createOfferItem(NegotiationOffer $offer, RfqItem $rfqItem, array $payload, int $sortOrder): void
    {
        $quantity = $this->positiveInt($payload['quantity'] ?? null, 'quantity');
        $unitPrice = $this->nonNegativeMoney($payload['unit_price'] ?? null, 'unit_price');

        $row = new NegotiationOfferItem;
        $row->offer()->associate($offer);
        $row->rfqItem()->associate($rfqItem);
        $row->product_id = $rfqItem->product_id;
        $row->description = (string) ($payload['description'] ?? $rfqItem->item_name);
        $row->quantity = $quantity;
        $row->unit = (string) ($payload['unit'] ?? $rfqItem->unit ?? 'MT');
        $row->unit_price = $unitPrice;
        $row->line_total = $this->lineTotal($quantity, $unitPrice);
        $row->notes = $payload['notes'] ?? null;
        $row->product_snapshot = [
            'source' => 'negotiation_offer',
            'captured_at' => now()->toISOString(),
            'rfq_item' => $rfqItem->toApiArray(),
            'product_snapshot' => $rfqItem->product_snapshot,
        ];
        $row->sort_order = $payload['sort_order'] ?? $sortOrder;
        $row->save();
    }

    private function recalculateOfferTotals(NegotiationOffer $offer): void
    {
        $offer->loadMissing('items');
        $subtotal = '0.00';
        foreach ($offer->items as $item) {
            $line = $this->lineTotal((int) $item->quantity, (string) $item->unit_price);
            if ((string) $item->line_total !== $line) {
                $item->line_total = $line;
                $item->save();
            }
            $subtotal = bcadd($subtotal, $line, 2);
        }

        $shipping = $this->money($offer->shipping_amount);
        $tax = $this->money($offer->tax_amount);
        $offer->subtotal = $subtotal;
        $offer->shipping_amount = $shipping;
        $offer->tax_amount = $tax;
        $offer->total = bcadd(bcadd($subtotal, $shipping, 2), $tax, 2);
        $offer->save();
    }

    private function actorSideForQuotation(Quotation $quotation, User $actor): ?string
    {
        if ($actor->company_id === null) {
            return null;
        }

        if ((int) $actor->company_id === (int) $quotation->rfq->company_id) {
            return NegotiationOffer::SIDE_BUYER;
        }

        if ((int) $actor->company_id === (int) $quotation->supplier_company_id) {
            return NegotiationOffer::SIDE_SUPPLIER;
        }

        return null;
    }

    private function resolveRfqItem(Negotiation $negotiation, mixed $rfqItemId): RfqItem
    {
        if ($rfqItemId === null || $rfqItemId === '') {
            throw ValidationException::withMessages([
                'rfq_item_id' => 'An RFQ item is required.',
            ]);
        }

        $rfqItem = RfqItem::query()->find((int) $rfqItemId);
        if ($rfqItem === null || (int) $rfqItem->rfq_id !== (int) $negotiation->rfq_id) {
            throw ValidationException::withMessages([
                'rfq_item_id' => 'RFQ item does not belong to this negotiation RFQ.',
            ]);
        }

        return $rfqItem;
    }

    private function lineTotal(int $quantity, string $unitPrice): string
    {
        return bcmul((string) $quantity, $this->money($unitPrice), 2);
    }

    private function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                'amount' => 'Amount must be numeric.',
            ]);
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function nonNegativeMoney(mixed $value, string $field): string
    {
        if ($value === null || $value === '' || ! is_numeric($value) || (float) $value < 0) {
            throw ValidationException::withMessages([
                $field => 'A valid non-negative amount is required.',
            ]);
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if ($value === null || $value === '' || ! is_numeric($value) || (int) $value != $value || (int) $value < 1) {
            throw ValidationException::withMessages([
                $field => 'A positive integer quantity is required.',
            ]);
        }

        return (int) $value;
    }
}
