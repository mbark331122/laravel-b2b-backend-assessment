<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class RmaItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'product_snapshot' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Rma, $this>
     */
    public function rma(): BelongsTo
    {
        return $this->belongsTo(Rma::class);
    }

    /**
     * @return BelongsTo<ShipmentItem, $this>
     */
    public function shipmentItem(): BelongsTo
    {
        return $this->belongsTo(ShipmentItem::class);
    }

    /**
     * @return BelongsTo<PurchaseOrderItem, $this>
     */
    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
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
            'rma_id' => $this->rma_id,
            'shipment_item_id' => $this->shipment_item_id,
            'purchase_order_item_id' => $this->purchase_order_item_id,
            'product_id' => $this->product_id,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'product_snapshot' => $this->product_snapshot,
            'sort_order' => $this->sort_order,
        ];
    }
}
