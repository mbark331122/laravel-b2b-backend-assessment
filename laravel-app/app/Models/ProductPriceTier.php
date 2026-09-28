<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['min_quantity', 'max_quantity', 'price', 'currency'])]
class ProductPriceTier extends Model
{
    protected $table = 'product_price_tiers';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_quantity' => 'integer',
            'max_quantity' => 'integer',
            'price' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'min_quantity' => $this->min_quantity,
            'max_quantity' => $this->max_quantity,
            'price' => (string) $this->price,
            'currency' => $this->currency,
        ];
    }
}
