<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

#[Fillable([
    'title',
    'description',
    'commodity',
    'specification',
    'quantity',
    'unit',
    'incoterm',
    'destination',
    'currency',
    'required_by_date',
])]
class Rfq extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_CLOSED = 'closed';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_CANCELLED,
        self::STATUS_CLOSED,
    ];

    /**
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_SUBMITTED, self::STATUS_CANCELLED],
        self::STATUS_SUBMITTED => [self::STATUS_CANCELLED, self::STATUS_CLOSED],
        self::STATUS_CANCELLED => [],
        self::STATUS_CLOSED => [],
    ];

    protected static function booted(): void
    {
        static::creating(function (Rfq $rfq): void {
            if ($rfq->status === null || $rfq->status === '') {
                $rfq->status = self::STATUS_DRAFT;
            }
            if (($rfq->title === null || $rfq->title === '') && filled($rfq->commodity)) {
                $rfq->title = $rfq->commodity;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'required_by_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<RfqItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(RfqItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<AiExtraction, $this>
     */
    public function aiExtractions(): HasMany
    {
        return $this->hasMany(AiExtraction::class);
    }

    /**
     * @return HasMany<RfqProposal, $this>
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(RfqProposal::class);
    }

    /**
     * @return HasMany<RfqDistribution, $this>
     */
    public function distributions(): HasMany
    {
        return $this->hasMany(RfqDistribution::class)->orderBy('id');
    }

    /**
     * @return HasMany<Quotation, $this>
     */
    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class)->orderBy('id');
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isDeletable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
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
            throw new InvalidArgumentException("Invalid RFQ lifecycle transition from {$this->status} to {$to}.");
        }

        if ($to === self::STATUS_SUBMITTED && $this->items()->count() < 1) {
            throw new InvalidArgumentException('RFQ must contain at least one item before submission.');
        }

        $this->status = $to;
        $this->save();
    }

    /**
     * Buyers see only their company RFQs.
     * Suppliers do not use this scope for inbox access — use distributedToSupplierCompany().
     * Admin is not tenant-scoped.
     *
     * @param  Builder<Rfq>  $query
     * @return Builder<Rfq>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        // Buyer RFQ list remains company-owned. Supplier inbox is a separate endpoint.
        return $query->where('company_id', $user->company_id);
    }

    /**
     * RFQs explicitly distributed (active) to the authenticated supplier company.
     *
     * @param  Builder<Rfq>  $query
     * @return Builder<Rfq>
     */
    public function scopeDistributedToSupplierCompany(Builder $query, int $supplierCompanyId): Builder
    {
        return $query->whereHas('distributions', function ($distributions) use ($supplierCompanyId): void {
            $distributions->active()->where('supplier_company_id', $supplierCompanyId);
        });
    }

    /**
     * Supplier-facing RFQ payload (no other suppliers / matching metadata).
     *
     * @return array<string, mixed>
     */
    public function toSupplierApiArray(?RfqDistribution $distribution = null): array
    {
        $this->loadMissing('items');

        $payload = [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'commodity' => $this->commodity,
            'specification' => $this->specification,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'incoterm' => $this->incoterm,
            'destination' => $this->destination,
            'currency' => $this->currency,
            'required_by_date' => $this->required_by_date?->format('Y-m-d'),
            'status' => $this->status,
            'items' => $this->items->map(fn (RfqItem $item) => $item->toApiArray())->values()->all(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];

        if ($distribution !== null) {
            $payload['distribution'] = [
                'id' => $distribution->id,
                'status' => $distribution->status,
                'distributed_at' => $distribution->distributed_at?->toISOString(),
            ];
        }

        return $payload;
    }

    /**
     * @param  Builder<Rfq>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Rfq>
     */
    public function scopeApplyFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['created_from'])) {
            $query->whereDate('created_at', '>=', (string) $filters['created_from']);
        }

        if (! empty($filters['created_to'])) {
            $query->whereDate('created_at', '<=', (string) $filters['created_to']);
        }

        if (! empty($filters['updated_from'])) {
            $query->whereDate('updated_at', '>=', (string) $filters['updated_from']);
        }

        if (! empty($filters['updated_to'])) {
            $query->whereDate('updated_at', '<=', (string) $filters['updated_to']);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $this->loadMissing('items');

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'title' => $this->title,
            'description' => $this->description,
            'commodity' => $this->commodity,
            'specification' => $this->specification,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'incoterm' => $this->incoterm,
            'destination' => $this->destination,
            'currency' => $this->currency,
            'required_by_date' => $this->required_by_date?->format('Y-m-d'),
            'status' => $this->status,
            'items' => $this->items->map(fn (RfqItem $item) => $item->toApiArray())->values()->all(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
