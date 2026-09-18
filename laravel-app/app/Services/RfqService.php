<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Rfq;
use App\Models\RfqItem;
use Illuminate\Validation\ValidationException;

class RfqService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function createItem(Rfq $rfq, array $payload): RfqItem
    {
        if (! $rfq->isEditable()) {
            throw ValidationException::withMessages([
                'rfq' => 'RFQ items can only be modified while the RFQ is in draft.',
            ]);
        }

        $item = new RfqItem;
        $item->rfq()->associate($rfq);
        $this->applyItemPayload($item, $payload);
        $item->sort_order = $payload['sort_order'] ?? (($rfq->items()->max('sort_order') ?? -1) + 1);
        $item->save();

        return $item->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateItem(RfqItem $item, array $payload): RfqItem
    {
        if (! $item->rfq->isEditable()) {
            throw ValidationException::withMessages([
                'rfq' => 'RFQ items can only be modified while the RFQ is in draft.',
            ]);
        }

        $this->applyItemPayload($item, $payload, replacing: true);
        $item->save();

        return $item->fresh();
    }

    public function deleteItem(RfqItem $item): void
    {
        if (! $item->rfq->isEditable()) {
            throw ValidationException::withMessages([
                'rfq' => 'RFQ items can only be modified while the RFQ is in draft.',
            ]);
        }

        $item->delete();
    }

    /**
     * Create a historical item snapshot from legacy commodity fields.
     */
    public function ensureLegacyItem(Rfq $rfq): void
    {
        if ($rfq->items()->exists()) {
            return;
        }

        $item = new RfqItem;
        $item->rfq()->associate($rfq);
        $item->item_name = $rfq->commodity;
        $item->unit = $rfq->unit;
        $item->quantity = $rfq->quantity;
        $item->requirements = $rfq->specification;
        $item->currency = $rfq->currency;
        $item->product_snapshot = [
            'source' => 'legacy_rfq_fields',
            'commodity' => $rfq->commodity,
            'specification' => $rfq->specification,
            'incoterm' => $rfq->incoterm,
            'destination' => $rfq->destination,
        ];
        $item->sort_order = 0;
        $item->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyItemPayload(RfqItem $item, array $payload, bool $replacing = false): void
    {
        if (array_key_exists('product_id', $payload) && $payload['product_id'] !== null) {
            $product = Product::query()->find($payload['product_id']);

            if ($product === null || ! $product->isBuyerVisible()) {
                throw ValidationException::withMessages([
                    'product_id' => 'The selected product is not available for RFQ referencing.',
                ]);
            }

            $product->loadMissing(['brand', 'category', 'specifications', 'priceTiers']);

            $item->product()->associate($product);
            $item->item_name = $payload['item_name'] ?? $product->name;
            $item->product_sku = $product->sku;
            $item->product_brand_name = $product->brand?->name;
            $item->unit = $payload['unit'] ?? $product->unit;
            $item->currency = $payload['currency'] ?? $product->currency;
            $item->product_snapshot = [
                'product_id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'brand' => $product->brand?->toApiArray(),
                'category' => $product->category?->toApiArray(),
                'unit' => $product->unit,
                'wholesale_price' => (string) $product->wholesale_price,
                'currency' => $product->currency,
                'minimum_order_quantity' => $product->minimum_order_quantity,
                'maximum_order_quantity' => $product->maximum_order_quantity,
                'quantity_increment' => $product->quantity_increment,
                'status' => $product->status,
                'specifications' => $product->specifications->map->toApiArray()->values()->all(),
                'price_tiers' => $product->priceTiers->map->toApiArray()->values()->all(),
                'captured_at' => now()->toISOString(),
            ];
        } elseif (! $replacing || array_key_exists('item_name', $payload)) {
            $item->product()->dissociate();
            $item->item_name = $payload['item_name'] ?? $item->item_name;
            $item->product_sku = $payload['product_sku'] ?? null;
            $item->product_brand_name = $payload['product_brand_name'] ?? null;
            $item->product_snapshot = $payload['product_snapshot'] ?? $item->product_snapshot;
            if (isset($payload['unit'])) {
                $item->unit = $payload['unit'];
            }
            if (array_key_exists('currency', $payload)) {
                $item->currency = $payload['currency'];
            }
        }

        if (isset($payload['quantity'])) {
            $item->quantity = (int) $payload['quantity'];
        }
        if (isset($payload['unit']) && $item->product_id === null) {
            $item->unit = $payload['unit'];
        }
        if (array_key_exists('target_price', $payload)) {
            $item->target_price = $payload['target_price'];
        }
        if (array_key_exists('currency', $payload) && $item->product_id === null) {
            $item->currency = $payload['currency'];
        }
        if (array_key_exists('requirements', $payload)) {
            $item->requirements = $payload['requirements'];
        }
        if (isset($payload['sort_order'])) {
            $item->sort_order = (int) $payload['sort_order'];
        }

        if ($item->item_name === null || $item->item_name === '') {
            throw ValidationException::withMessages([
                'item_name' => 'An item name is required.',
            ]);
        }
    }
}
