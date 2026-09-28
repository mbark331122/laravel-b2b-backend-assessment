<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class NegotiationOfferItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'product_snapshot' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<NegotiationOffer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(NegotiationOffer::class, 'negotiation_offer_id');
    }

    /**
     * @return BelongsTo<RfqItem, $this>
     */
    public function rfqItem(): BelongsTo
    {
        return $this->belongsTo(RfqItem::class, 'rfq_item_id');
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
            'negotiation_offer_id' => $this->negotiation_offer_id,
            'rfq_item_id' => $this->rfq_item_id,
            'product_id' => $this->product_id,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'unit_price' => (string) $this->unit_price,
            'line_total' => (string) $this->line_total,
            'notes' => $this->notes,
            'product_snapshot' => $this->product_snapshot,
            'sort_order' => $this->sort_order,
        ];
    }
}
