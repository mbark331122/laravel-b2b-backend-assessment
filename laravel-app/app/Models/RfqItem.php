<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'item_name',
    'unit',
    'quantity',
    'target_price',
    'currency',
    'requirements',
    'sort_order',
])]
class RfqItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'target_price' => 'decimal:2',
            'product_snapshot' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Rfq, $this>
     */
    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
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
            'rfq_id' => $this->rfq_id,
            'product_id' => $this->product_id,
            'item_name' => $this->item_name,
            'product_sku' => $this->product_sku,
            'product_brand_name' => $this->product_brand_name,
            'unit' => $this->unit,
            'quantity' => $this->quantity,
            'target_price' => $this->target_price !== null ? (string) $this->target_price : null,
            'currency' => $this->currency,
            'requirements' => $this->requirements,
            'product_snapshot' => $this->product_snapshot,
            'sort_order' => $this->sort_order,
        ];
    }
}
