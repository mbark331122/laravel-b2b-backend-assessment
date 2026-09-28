<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\ProductSpecification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductCatalogService
{
    /**
     * @param  list<array{name: string, value: string, unit?: string|null, sort_order?: int}>  $specs
     */
    public function syncSpecifications(Product $product, array $specs): void
    {
        DB::transaction(function () use ($product, $specs): void {
            $product->specifications()->delete();

            foreach (array_values($specs) as $index => $spec) {
                $row = new ProductSpecification;
                $row->product()->associate($product);
                $row->name = $spec['name'];
                $row->value = $spec['value'];
                $row->unit = $spec['unit'] ?? null;
                $row->sort_order = $spec['sort_order'] ?? $index;
                $row->save();
            }
        });
    }

    /**
     * @param  list<array{min_quantity: int, max_quantity?: int|null, price: numeric, currency: string}>  $tiers
     */
    public function syncPriceTiers(Product $product, array $tiers): void
    {
        $normalized = collect($tiers)
            ->map(fn (array $tier) => [
                'min_quantity' => (int) $tier['min_quantity'],
                'max_quantity' => array_key_exists('max_quantity', $tier) && $tier['max_quantity'] !== null
                    ? (int) $tier['max_quantity']
                    : null,
                'price' => $tier['price'],
                'currency' => strtoupper((string) $tier['currency']),
            ])
            ->sortBy('min_quantity')
            ->values();

        $this->assertValidTierRanges($normalized->all());

        DB::transaction(function () use ($product, $normalized): void {
            $product->priceTiers()->delete();

            foreach ($normalized as $tier) {
                $row = new ProductPriceTier;
                $row->product()->associate($product);
                $row->min_quantity = $tier['min_quantity'];
                $row->max_quantity = $tier['max_quantity'];
                $row->price = $tier['price'];
                $row->currency = $tier['currency'];
                $row->save();
            }
        });
    }

    /**
     * @param  list<array{min_quantity: int, max_quantity: int|null, price: mixed, currency: string}>  $tiers
     */
    private function assertValidTierRanges(array $tiers): void
    {
        $previousMax = null;

        foreach ($tiers as $index => $tier) {
            if ($tier['min_quantity'] < 1) {
                throw ValidationException::withMessages([
                    "tiers.$index.min_quantity" => 'Tier minimum quantity must be at least 1.',
                ]);
            }

            if ($tier['max_quantity'] !== null && $tier['max_quantity'] < $tier['min_quantity']) {
                throw ValidationException::withMessages([
                    "tiers.$index.max_quantity" => 'Tier maximum quantity must be greater than or equal to minimum quantity.',
                ]);
            }

            if ($previousMax !== null && $tier['min_quantity'] <= $previousMax) {
                throw ValidationException::withMessages([
                    "tiers.$index.min_quantity" => 'Pricing tiers must not overlap and must be ordered by quantity.',
                ]);
            }

            $previousMax = $tier['max_quantity'] ?? PHP_INT_MAX;

            if ($index < count($tiers) - 1 && $tier['max_quantity'] === null) {
                throw ValidationException::withMessages([
                    "tiers.$index.max_quantity" => 'Only the last pricing tier may have an open-ended maximum quantity.',
                ]);
            }
        }
    }
}
