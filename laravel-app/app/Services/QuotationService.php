<?php

namespace App\Services;

use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Rfq;
use App\Models\RfqDistribution;
use App\Models\RfqItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class QuotationService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function createForDistribution(RfqDistribution $distribution, int $supplierCompanyId, array $payload): Quotation
    {
        return DB::transaction(function () use ($distribution, $supplierCompanyId, $payload) {
            /** @var RfqDistribution $lockedDistribution */
            $lockedDistribution = RfqDistribution::query()
                ->whereKey($distribution->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDistributionEligibleForCreate($lockedDistribution, $supplierCompanyId);

            Quotation::query()
                ->where('rfq_distribution_id', $lockedDistribution->id)
                ->where('status', Quotation::STATUS_SUBMITTED)
                ->lockForUpdate()
                ->get()
                ->each(fn (Quotation $quotation) => $quotation->refreshExpiration());

            $existingActive = Quotation::query()
                ->where('rfq_distribution_id', $lockedDistribution->id)
                ->whereIn('status', [Quotation::STATUS_DRAFT, Quotation::STATUS_SUBMITTED])
                ->lockForUpdate()
                ->exists();

            if ($existingActive) {
                throw ValidationException::withMessages([
                    'quotation' => 'An active quotation already exists for this distribution.',
                ]);
            }

            $quotation = new Quotation;
            $quotation->rfq()->associate($lockedDistribution->rfq);
            $quotation->distribution()->associate($lockedDistribution);
            $quotation->supplier_company_id = $supplierCompanyId;
            $quotation->status = Quotation::STATUS_DRAFT;
            $quotation->currency = strtoupper((string) ($payload['currency'] ?? $lockedDistribution->rfq->currency ?? 'USD'));
            $quotation->valid_until = $payload['valid_until'] ?? null;
            $quotation->notes = $payload['notes'] ?? null;
            $quotation->shipping_amount = $this->money($payload['shipping_amount'] ?? 0);
            $quotation->tax_amount = $this->money($payload['tax_amount'] ?? 0);
            $quotation->subtotal = '0.00';
            $quotation->total = '0.00';
            $quotation->active_lock = $lockedDistribution->id;
            $quotation->save();

            foreach ($payload['items'] ?? [] as $itemPayload) {
                $this->createItem($quotation, $itemPayload);
            }

            $this->recalculateTotals($quotation);

            return $quotation->fresh(['items', 'supplierCompany.supplierProfile']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateDraft(Quotation $quotation, array $payload): Quotation
    {
        $this->assertDraft($quotation);

        if (array_key_exists('currency', $payload)) {
            $quotation->currency = strtoupper((string) $payload['currency']);
        }
        if (array_key_exists('valid_until', $payload)) {
            $quotation->valid_until = $payload['valid_until'];
        }
        if (array_key_exists('notes', $payload)) {
            $quotation->notes = $payload['notes'];
        }
        if (array_key_exists('shipping_amount', $payload)) {
            $quotation->shipping_amount = $this->money($payload['shipping_amount']);
        }
        if (array_key_exists('tax_amount', $payload)) {
            $quotation->tax_amount = $this->money($payload['tax_amount']);
        }

        // Ignore any client-supplied totals.
        $quotation->save();
        $this->recalculateTotals($quotation);

        return $quotation->fresh(['items', 'supplierCompany.supplierProfile']);
    }

    public function deleteDraft(Quotation $quotation): void
    {
        $this->assertDraft($quotation);
        $quotation->delete();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createItem(Quotation $quotation, array $payload): QuotationItem
    {
        $this->assertDraft($quotation);

        $rfqItem = $this->resolveRfqItem($quotation, $payload['rfq_item_id'] ?? null);

        if ($quotation->items()->where('rfq_item_id', $rfqItem->id)->exists()) {
            throw ValidationException::withMessages([
                'rfq_item_id' => 'A quotation item already exists for this RFQ item.',
            ]);
        }

        $quantity = $this->positiveInt($payload['quantity'] ?? null, 'quantity');
        $unitPrice = $this->nonNegativeMoney($payload['unit_price'] ?? null, 'unit_price');

        $item = new QuotationItem;
        $item->quotation()->associate($quotation);
        $item->rfqItem()->associate($rfqItem);
        $item->product_id = $rfqItem->product_id;
        $item->description = (string) ($payload['description'] ?? $rfqItem->item_name);
        $item->quantity = $quantity;
        $item->unit = (string) ($payload['unit'] ?? $rfqItem->unit ?? 'MT');
        $item->unit_price = $unitPrice;
        $item->line_total = $this->lineTotal($quantity, $unitPrice);
        $item->notes = $payload['notes'] ?? null;
        $item->product_snapshot = $this->buildItemSnapshot($rfqItem);
        $item->sort_order = $payload['sort_order'] ?? (($quotation->items()->max('sort_order') ?? -1) + 1);
        $item->save();

        $this->recalculateTotals($quotation);

        return $item->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateItem(QuotationItem $item, array $payload): QuotationItem
    {
        $quotation = $item->quotation;
        $this->assertDraft($quotation);

        if (array_key_exists('rfq_item_id', $payload) && (int) $payload['rfq_item_id'] !== (int) $item->rfq_item_id) {
            throw ValidationException::withMessages([
                'rfq_item_id' => 'RFQ item reference cannot be changed.',
            ]);
        }

        if (array_key_exists('description', $payload)) {
            $item->description = (string) $payload['description'];
        }
        if (array_key_exists('unit', $payload)) {
            $item->unit = (string) $payload['unit'];
        }
        if (array_key_exists('notes', $payload)) {
            $item->notes = $payload['notes'];
        }
        if (array_key_exists('quantity', $payload)) {
            $item->quantity = $this->positiveInt($payload['quantity'], 'quantity');
        }
        if (array_key_exists('unit_price', $payload)) {
            $item->unit_price = $this->nonNegativeMoney($payload['unit_price'], 'unit_price');
        }

        $item->line_total = $this->lineTotal((int) $item->quantity, (string) $item->unit_price);
        $item->save();

        $this->recalculateTotals($quotation);

        return $item->fresh();
    }

    public function deleteItem(QuotationItem $item): void
    {
        $quotation = $item->quotation;
        $this->assertDraft($quotation);
        $item->delete();
        $this->recalculateTotals($quotation);
    }

    public function submit(Quotation $quotation): Quotation
    {
        $quotation->refreshExpiration();
        $this->assertDraft($quotation);
        $quotation->loadMissing(['distribution', 'rfq', 'items']);

        if (! $quotation->distribution?->isActive()) {
            throw ValidationException::withMessages([
                'quotation' => 'Cannot submit a quotation for a withdrawn distribution.',
            ]);
        }

        if ($quotation->rfq?->status !== Rfq::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'quotation' => 'RFQ must be submitted to receive quotations.',
            ]);
        }

        $this->assertAllRfqItemsQuoted($quotation);
        $this->recalculateTotals($quotation);

        try {
            $quotation->transitionTo(Quotation::STATUS_SUBMITTED);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'quotation' => $exception->getMessage(),
            ]);
        }

        return $quotation->fresh(['items', 'supplierCompany.supplierProfile']);
    }

    public function withdraw(Quotation $quotation): Quotation
    {
        $quotation->refreshExpiration();

        try {
            $quotation->transitionTo(Quotation::STATUS_WITHDRAWN);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'quotation' => $exception->getMessage(),
            ]);
        }

        return $quotation->fresh(['items', 'supplierCompany.supplierProfile']);
    }

    public function recalculateTotals(Quotation $quotation): void
    {
        $quotation->loadMissing('items');

        $subtotal = '0.00';
        foreach ($quotation->items as $item) {
            $line = $this->lineTotal((int) $item->quantity, (string) $item->unit_price);
            if ((string) $item->line_total !== $line) {
                $item->line_total = $line;
                $item->save();
            }
            $subtotal = bcadd($subtotal, $line, 2);
        }

        $shipping = $this->money($quotation->shipping_amount);
        $tax = $this->money($quotation->tax_amount);
        $total = bcadd(bcadd($subtotal, $shipping, 2), $tax, 2);

        $quotation->subtotal = $subtotal;
        $quotation->shipping_amount = $shipping;
        $quotation->tax_amount = $tax;
        $quotation->total = $total;
        $quotation->save();
    }

    private function assertDistributionEligibleForCreate(RfqDistribution $distribution, int $supplierCompanyId): void
    {
        $distribution->loadMissing('rfq');

        if ((int) $distribution->supplier_company_id !== $supplierCompanyId) {
            throw ValidationException::withMessages([
                'distribution' => 'Distribution does not belong to the authenticated supplier.',
            ]);
        }

        if (! $distribution->isActive()) {
            throw ValidationException::withMessages([
                'distribution' => 'Only active distributions can receive quotations.',
            ]);
        }

        if ($distribution->rfq?->status !== Rfq::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'rfq' => 'Only submitted RFQs can receive quotations.',
            ]);
        }
    }

    private function assertDraft(Quotation $quotation): void
    {
        $quotation->refreshExpiration();

        if (! $quotation->isDraft()) {
            throw ValidationException::withMessages([
                'quotation' => 'Only draft quotations can be modified.',
            ]);
        }
    }

    private function assertAllRfqItemsQuoted(Quotation $quotation): void
    {
        $rfqItemIds = $quotation->rfq->items()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();
        $quotedIds = $quotation->items()->pluck('rfq_item_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();

        if ($rfqItemIds->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'RFQ has no items to quote.',
            ]);
        }

        if ($rfqItemIds->all() !== $quotedIds->all()) {
            throw ValidationException::withMessages([
                'items' => 'Quotation must include every RFQ item before submission.',
            ]);
        }
    }

    private function resolveRfqItem(Quotation $quotation, mixed $rfqItemId): RfqItem
    {
        if ($rfqItemId === null || $rfqItemId === '') {
            throw ValidationException::withMessages([
                'rfq_item_id' => 'An RFQ item is required.',
            ]);
        }

        $rfqItem = RfqItem::query()->find((int) $rfqItemId);

        if ($rfqItem === null || (int) $rfqItem->rfq_id !== (int) $quotation->rfq_id) {
            throw ValidationException::withMessages([
                'rfq_item_id' => 'RFQ item does not belong to this quotation RFQ.',
            ]);
        }

        return $rfqItem;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildItemSnapshot(RfqItem $rfqItem): array
    {
        return [
            'source' => 'rfq_item',
            'captured_at' => now()->toISOString(),
            'rfq_item' => $rfqItem->toApiArray(),
            'product_snapshot' => $rfqItem->product_snapshot,
        ];
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
        if ($value === null || $value === '' || ! is_numeric($value)) {
            throw ValidationException::withMessages([
                $field => 'A valid non-negative amount is required.',
            ]);
        }

        if ((float) $value < 0) {
            throw ValidationException::withMessages([
                $field => 'Amount must be non-negative.',
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
