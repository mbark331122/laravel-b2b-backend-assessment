<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class DeliveryConfirmation extends Model
{
    public const STATUS_CONFIRMED = 'confirmed';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_CONFIRMED,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function buyerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'buyer_company_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function supplierCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'supplier_company_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    /**
     * @param  Builder<DeliveryConfirmation>  $query
     * @return Builder<DeliveryConfirmation>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where(function ($inner) use ($user): void {
            $inner->where('buyer_company_id', $user->company_id)
                ->orWhere('supplier_company_id', $user->company_id);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $this->loadMissing([
            'shipment',
            'purchaseOrder',
            'buyerCompany',
            'supplierCompany.supplierProfile',
            'confirmedBy',
        ]);

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'shipment_id' => $this->shipment_id,
            'shipment_number' => $this->shipment?->number,
            'purchase_order_id' => $this->purchase_order_id,
            'purchase_order_number' => $this->purchaseOrder?->number,
            'notes' => $this->notes,
            'buyer_company' => [
                'id' => $this->buyer_company_id,
                'name' => $this->buyerCompany?->name,
            ],
            'supplier_company' => [
                'id' => $this->supplier_company_id,
                'name' => $this->supplierCompany?->name,
                'display_name' => $this->supplierCompany?->supplierProfile?->display_name,
            ],
            'confirmed_by' => [
                'id' => $this->confirmed_by_user_id,
                'name' => $this->confirmedBy?->name,
                'email' => $this->confirmedBy?->email,
            ],
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
