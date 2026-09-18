<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use InvalidArgumentException;

#[Fillable([])]
class PurchaseOrder extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_SUPPLIER_CONFIRMATION = 'pending_supplier_confirmation';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_COMPLETED = 'completed';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING_SUPPLIER_CONFIRMATION,
        self::STATUS_CONFIRMED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
        self::STATUS_COMPLETED,
    ];

    /**
     * Explicit lifecycle transition matrix.
     *
     * draft → pending_supplier_confirmation | cancelled
     * pending_supplier_confirmation → confirmed | rejected | cancelled
     * confirmed → completed
     * rejected / cancelled / completed → (terminal)
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [
            self::STATUS_PENDING_SUPPLIER_CONFIRMATION,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_PENDING_SUPPLIER_CONFIRMATION => [
            self::STATUS_CONFIRMED,
            self::STATUS_REJECTED,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_CONFIRMED => [
            self::STATUS_COMPLETED,
        ],
        self::STATUS_REJECTED => [],
        self::STATUS_CANCELLED => [],
        self::STATUS_COMPLETED => [],
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shipping_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
            'submitted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Negotiation, $this>
     */
    public function negotiation(): BelongsTo
    {
        return $this->belongsTo(Negotiation::class);
    }

    /**
     * @return BelongsTo<NegotiationOffer, $this>
     */
    public function acceptedOffer(): BelongsTo
    {
        return $this->belongsTo(NegotiationOffer::class, 'accepted_offer_id');
    }

    /**
     * @return BelongsTo<Quotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /**
     * @return BelongsTo<Rfq, $this>
     */
    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    /**
     * @return BelongsTo<RfqDistribution, $this>
     */
    public function distribution(): BelongsTo
    {
        return $this->belongsTo(RfqDistribution::class, 'rfq_distribution_id');
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
     * @return HasMany<PurchaseOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasOne<Invoice, $this>
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isCommerciallyImmutable(): bool
    {
        return $this->status !== self::STATUS_DRAFT;
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
            throw new InvalidArgumentException("Invalid purchase order lifecycle transition from {$this->status} to {$to}.");
        }

        if ($to === self::STATUS_PENDING_SUPPLIER_CONFIRMATION) {
            $this->submitted_at = now();
        }
        if ($to === self::STATUS_CONFIRMED) {
            $this->confirmed_at = now();
        }
        if ($to === self::STATUS_REJECTED) {
            $this->rejected_at = now();
        }
        if ($to === self::STATUS_CANCELLED) {
            $this->cancelled_at = now();
        }
        if ($to === self::STATUS_COMPLETED) {
            $this->completed_at = now();
        }

        $this->status = $to;
        $this->save();
    }

    /**
     * @param  Builder<PurchaseOrder>  $query
     * @return Builder<PurchaseOrder>
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
            'buyerCompany',
            'supplierCompany.supplierProfile',
        ]);

        return [
            'id' => $this->id,
            'number' => $this->number,
            'negotiation_id' => $this->negotiation_id,
            'accepted_offer_id' => $this->accepted_offer_id,
            'quotation_id' => $this->quotation_id,
            'rfq_id' => $this->rfq_id,
            'rfq_distribution_id' => $this->rfq_distribution_id,
            'status' => $this->status,
            'currency' => $this->currency,
            'shipping_amount' => (string) $this->shipping_amount,
            'tax_amount' => (string) $this->tax_amount,
            'subtotal' => (string) $this->subtotal,
            'total' => (string) $this->total,
            'notes' => $this->notes,
            'rejection_reason' => $this->rejection_reason,
            'buyer_company' => [
                'id' => $this->buyer_company_id,
                'name' => $this->buyerCompany?->name,
            ],
            'supplier_company' => [
                'id' => $this->supplier_company_id,
                'name' => $this->supplierCompany?->name,
                'display_name' => $this->supplierCompany?->supplierProfile?->display_name,
            ],
            'items' => $this->items->map(fn (PurchaseOrderItem $item) => $item->toApiArray())->values()->all(),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'rejected_at' => $this->rejected_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
