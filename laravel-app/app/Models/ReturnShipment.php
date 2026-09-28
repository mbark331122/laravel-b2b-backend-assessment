<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

#[Fillable([])]
class ReturnShipment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SHIPPED,
        self::STATUS_DELIVERED,
        self::STATUS_CANCELLED,
    ];

    /**
     * @var list<string>
     */
    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SHIPPED,
    ];

    /**
     * Transition matrix.
     *
     * pending → shipped | cancelled
     * shipped → delivered | cancelled
     * delivered / cancelled → terminal
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING => [
            self::STATUS_SHIPPED,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_SHIPPED => [
            self::STATUS_DELIVERED,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_DELIVERED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origin_address_snapshot' => 'array',
            'destination_address_snapshot' => 'array',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
     * @return HasMany<ReturnShipmentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ReturnShipmentItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isOperationallyEditable(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_DELIVERED,
            self::STATUS_CANCELLED,
        ], true);
    }

    /**
     * @return list<string>
     */
    public function allowedTransitions(): array
    {
        return self::TRANSITIONS[$this->status] ?? [];
    }

    public function transitionTo(string $to): void
    {
        if (! in_array($to, $this->allowedTransitions(), true)) {
            throw new InvalidArgumentException("Invalid return shipment lifecycle transition from {$this->status} to {$to}.");
        }

        if ($to === self::STATUS_SHIPPED) {
            $this->shipped_at = now();
        }
        if ($to === self::STATUS_DELIVERED) {
            $this->delivered_at = now();
            $this->active_lock = null;
        }
        if ($to === self::STATUS_CANCELLED) {
            $this->cancelled_at = now();
            $this->active_lock = null;
        }

        $this->status = $to;
        $this->save();
    }

    /**
     * @param  Builder<ReturnShipment>  $query
     * @return Builder<ReturnShipment>
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
            'items',
            'rma',
            'shipment',
            'purchaseOrder',
            'buyerCompany',
            'supplierCompany.supplierProfile',
        ]);

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'rma_id' => $this->rma_id,
            'rma_number' => $this->rma?->number,
            'shipment_id' => $this->shipment_id,
            'shipment_number' => $this->shipment?->number,
            'purchase_order_id' => $this->purchase_order_id,
            'purchase_order_number' => $this->purchaseOrder?->number,
            'carrier' => $this->carrier,
            'tracking_number' => $this->tracking_number,
            'shipping_method' => $this->shipping_method,
            'origin_address_snapshot' => $this->origin_address_snapshot,
            'destination_address_snapshot' => $this->destination_address_snapshot,
            'buyer_company' => [
                'id' => $this->buyer_company_id,
                'name' => $this->buyerCompany?->name,
            ],
            'supplier_company' => [
                'id' => $this->supplier_company_id,
                'name' => $this->supplierCompany?->name,
                'display_name' => $this->supplierCompany?->supplierProfile?->display_name,
            ],
            'items' => $this->items->map(fn (ReturnShipmentItem $item) => $item->toApiArray())->values()->all(),
            'shipped_at' => $this->shipped_at?->toISOString(),
            'delivered_at' => $this->delivered_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
