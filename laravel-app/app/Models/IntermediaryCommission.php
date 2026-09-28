<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class IntermediaryCommission extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<PurchaseOrderParty, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderParty::class, 'purchase_order_party_id');
    }

    /**
     * @param  Builder<IntermediaryCommission>  $query
     * @return Builder<IntermediaryCommission>
     */
    public function scopeForPurchaseOrder(Builder $query, int $purchaseOrderId): Builder
    {
        return $query->where('purchase_order_id', $purchaseOrderId);
    }
}
