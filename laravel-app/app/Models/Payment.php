<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

#[Fillable([])]
class Payment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PAID,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    public const METHOD_CASH = 'cash';

    public const METHOD_MANUAL = 'manual';

    /**
     * @var list<string>
     */
    public const METHODS = [
        self::METHOD_BANK_TRANSFER,
        self::METHOD_CASH,
        self::METHOD_MANUAL,
    ];

    /**
     * Transition matrix.
     *
     * pending → paid | failed | cancelled
     * paid / failed / cancelled → terminal
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING => [
            self::STATUS_PAID,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_PAID => [],
        self::STATUS_FAILED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
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

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_PAID,
            self::STATUS_FAILED,
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
            throw new InvalidArgumentException("Invalid payment lifecycle transition from {$this->status} to {$to}.");
        }

        if ($to === self::STATUS_PAID) {
            $this->paid_at = now();
        }
        if ($to === self::STATUS_FAILED) {
            $this->failed_at = now();
        }
        if ($to === self::STATUS_CANCELLED) {
            $this->cancelled_at = now();
        }

        if (in_array($to, [self::STATUS_PAID, self::STATUS_FAILED, self::STATUS_CANCELLED], true)) {
            $this->active_lock = null;
        }

        $this->status = $to;
        $this->save();
    }

    /**
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
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
            'invoice',
            'buyerCompany',
            'supplierCompany.supplierProfile',
        ]);

        return [
            'id' => $this->id,
            'number' => $this->number,
            'invoice_id' => $this->invoice_id,
            'invoice_number' => $this->invoice?->number,
            'status' => $this->status,
            'method' => $this->method,
            'currency' => $this->currency,
            'amount' => (string) $this->amount,
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
            'paid_at' => $this->paid_at?->toISOString(),
            'failed_at' => $this->failed_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
