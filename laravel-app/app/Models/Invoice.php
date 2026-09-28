<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

#[Fillable([])]
class Invoice extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_VOIDED = 'voided';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ISSUED,
        self::STATUS_VOIDED,
        self::STATUS_PAID,
        self::STATUS_CANCELLED,
    ];

    /**
     * Transition matrix.
     *
     * draft → issued | cancelled
     * issued → voided | paid (paid only via PaymentService::markPaid)
     * voided / cancelled / paid → terminal
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [
            self::STATUS_ISSUED,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_ISSUED => [
            self::STATUS_VOIDED,
            self::STATUS_PAID,
        ],
        self::STATUS_VOIDED => [],
        self::STATUS_PAID => [],
        self::STATUS_CANCELLED => [],
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
            'buyer_snapshot' => 'array',
            'supplier_snapshot' => 'array',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'voided_at' => 'datetime',
            'paid_at' => 'datetime',
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
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderByDesc('id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isCommerciallyImmutable(): bool
    {
        return in_array($this->status, [
            self::STATUS_ISSUED,
            self::STATUS_VOIDED,
            self::STATUS_PAID,
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
            throw new InvalidArgumentException("Invalid invoice lifecycle transition from {$this->status} to {$to}.");
        }

        if ($to === self::STATUS_ISSUED) {
            $this->issued_at = now();
        }
        if ($to === self::STATUS_CANCELLED) {
            $this->cancelled_at = now();
        }
        if ($to === self::STATUS_VOIDED) {
            $this->voided_at = now();
        }
        if ($to === self::STATUS_PAID) {
            $this->paid_at = now();
        }

        $this->status = $to;
        $this->save();
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
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
            'purchase_order_id' => $this->purchase_order_id,
            'purchase_order_number' => $this->purchase_order_number,
            'status' => $this->status,
            'currency' => $this->currency,
            'shipping_amount' => (string) $this->shipping_amount,
            'tax_amount' => (string) $this->tax_amount,
            'subtotal' => (string) $this->subtotal,
            'total' => (string) $this->total,
            'notes' => $this->notes,
            'cancellation_reason' => $this->cancellation_reason,
            'void_reason' => $this->void_reason,
            'buyer_company' => [
                'id' => $this->buyer_company_id,
                'name' => $this->buyerCompany?->name,
            ],
            'supplier_company' => [
                'id' => $this->supplier_company_id,
                'name' => $this->supplierCompany?->name,
                'display_name' => $this->supplierCompany?->supplierProfile?->display_name,
            ],
            'buyer_snapshot' => $this->buyer_snapshot,
            'supplier_snapshot' => $this->supplier_snapshot,
            'items' => $this->items->map(fn (InvoiceItem $item) => $item->toApiArray())->values()->all(),
            'issued_at' => $this->issued_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'voided_at' => $this->voided_at?->toISOString(),
            'paid_at' => $this->paid_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
