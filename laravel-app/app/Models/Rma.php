<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

#[Fillable([])]
class Rma extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_RECEIVED,
        self::STATUS_CLOSED,
        self::STATUS_CANCELLED,
    ];

    /**
     * @var list<string>
     */
    public const ACTIVE_STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_APPROVED,
        self::STATUS_RECEIVED,
    ];

    /**
     * @var list<string>
     */
    public const TERMINAL_STATUSES = [
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
        self::STATUS_CLOSED,
    ];

    public const REASON_DAMAGED = 'damaged';

    public const REASON_DEFECTIVE = 'defective';

    public const REASON_INCORRECT_ITEM = 'incorrect_item';

    public const REASON_INCORRECT_QUANTITY = 'incorrect_quantity';

    public const REASON_QUALITY_ISSUE = 'quality_issue';

    public const REASON_OTHER = 'other';

    /**
     * @var list<string>
     */
    public const REASONS = [
        self::REASON_DAMAGED,
        self::REASON_DEFECTIVE,
        self::REASON_INCORRECT_ITEM,
        self::REASON_INCORRECT_QUANTITY,
        self::REASON_QUALITY_ISSUE,
        self::REASON_OTHER,
    ];

    /**
     * Transition matrix.
     *
     * requested → approved | rejected | cancelled
     * approved → received
     * received → closed
     * rejected / cancelled / closed → terminal
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_REQUESTED => [
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_APPROVED => [
            self::STATUS_RECEIVED,
        ],
        self::STATUS_RECEIVED => [
            self::STATUS_CLOSED,
        ],
        self::STATUS_REJECTED => [],
        self::STATUS_CANCELLED => [],
        self::STATUS_CLOSED => [],
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'received_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * @return HasMany<RmaItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(RmaItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /**
     * @return list<string>
     */
    public function allowedTransitions(): array
    {
        return self::TRANSITIONS[$this->status] ?? [];
    }

    public function transitionTo(string $to, ?User $reviewer = null): void
    {
        if (! in_array($to, $this->allowedTransitions(), true)) {
            throw new InvalidArgumentException("Invalid RMA lifecycle transition from {$this->status} to {$to}.");
        }

        if ($to === self::STATUS_APPROVED) {
            $this->approved_at = now();
            $this->reviewed_at = now();
            if ($reviewer) {
                $this->reviewed_by_user_id = $reviewer->id;
            }
        }
        if ($to === self::STATUS_REJECTED) {
            $this->rejected_at = now();
            $this->reviewed_at = now();
            if ($reviewer) {
                $this->reviewed_by_user_id = $reviewer->id;
            }
        }
        if ($to === self::STATUS_RECEIVED) {
            $this->received_at = now();
        }
        if ($to === self::STATUS_CLOSED) {
            $this->closed_at = now();
            $this->active_lock = null;
        }
        if ($to === self::STATUS_CANCELLED) {
            $this->cancelled_at = now();
            $this->active_lock = null;
        }
        if ($to === self::STATUS_REJECTED) {
            $this->active_lock = null;
        }

        $this->status = $to;
        $this->save();
    }

    /**
     * @param  Builder<Rma>  $query
     * @return Builder<Rma>
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
            'shipment',
            'purchaseOrder',
            'buyerCompany',
            'supplierCompany.supplierProfile',
            'requestedBy',
            'reviewedBy',
        ]);

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'rejection_reason' => $this->rejection_reason,
            'shipment_id' => $this->shipment_id,
            'shipment_number' => $this->shipment?->number,
            'purchase_order_id' => $this->purchase_order_id,
            'purchase_order_number' => $this->purchaseOrder?->number,
            'buyer_company' => [
                'id' => $this->buyer_company_id,
                'name' => $this->buyerCompany?->name,
            ],
            'supplier_company' => [
                'id' => $this->supplier_company_id,
                'name' => $this->supplierCompany?->name,
                'display_name' => $this->supplierCompany?->supplierProfile?->display_name,
            ],
            'requested_by' => [
                'id' => $this->requested_by_user_id,
                'name' => $this->requestedBy?->name,
                'email' => $this->requestedBy?->email,
            ],
            'reviewed_by' => $this->reviewed_by_user_id ? [
                'id' => $this->reviewed_by_user_id,
                'name' => $this->reviewedBy?->name,
                'email' => $this->reviewedBy?->email,
            ] : null,
            'items' => $this->items->map(fn (RmaItem $item) => $item->toApiArray())->values()->all(),
            'requested_at' => $this->requested_at?->toISOString(),
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'approved_at' => $this->approved_at?->toISOString(),
            'rejected_at' => $this->rejected_at?->toISOString(),
            'received_at' => $this->received_at?->toISOString(),
            'closed_at' => $this->closed_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
