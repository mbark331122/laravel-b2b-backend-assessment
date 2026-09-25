<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class ReturnShipmentItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_snapshot' => 'decimal:2',
            'line_total_snapshot' => 'decimal:2',
            'product_snapshot' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ReturnShipment, $this>
     */
    public function returnShipment(): BelongsTo
    {
        return $this->belongsTo(ReturnShipment::class);
    }

    /**
     * @return BelongsTo<RmaItem, $this>
     */
    public function rmaItem(): BelongsTo
    {
        return $this->belongsTo(RmaItem::class);
    }

    /**
     * @return BelongsTo<ShipmentItem, $this>
     */
    public function shipmentItem(): BelongsTo
    {
        return $this->belongsTo(ShipmentItem::class);
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
            'return_shipment_id' => $this->return_shipment_id,
            'rma_item_id' => $this->rma_item_id,
            'shipment_item_id' => $this->shipment_item_id,
            'product_id' => $this->product_id,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'unit_price_snapshot' => (string) $this->unit_price_snapshot,
            'line_total_snapshot' => (string) $this->line_total_snapshot,
            'currency' => $this->currency,
            'product_snapshot' => $this->product_snapshot,
            'sort_order' => $this->sort_order,
        ];
    }
}
