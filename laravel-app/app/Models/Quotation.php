<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

#[Fillable([])]
class Quotation extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUS_EXPIRED = 'expired';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_WITHDRAWN,
        self::STATUS_EXPIRED,
    ];

    /**
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_SUBMITTED, self::STATUS_WITHDRAWN],
        self::STATUS_SUBMITTED => [self::STATUS_WITHDRAWN, self::STATUS_EXPIRED],
        self::STATUS_WITHDRAWN => [],
        self::STATUS_EXPIRED => [],
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_until' => 'date',
            'shipping_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
            'submitted_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
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
    public function supplierCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'supplier_company_id');
    }

    /**
     * @return HasMany<QuotationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isEditable(): bool
    {
        return $this->isDraft();
    }

    public function isDeletable(): bool
    {
        return $this->isDraft();
    }

    public function isActiveOffer(): bool
    {
        $this->refreshExpiration();

        return $this->status === self::STATUS_SUBMITTED;
    }

    /**
     * Deterministic expiration: submitted + past valid_until => expired.
     * valid_until is inclusive through that calendar day.
     */
    public function refreshExpiration(): bool
    {
        if ($this->status !== self::STATUS_SUBMITTED) {
            return false;
        }

        if ($this->valid_until === null) {
            return false;
        }

        if ($this->valid_until->copy()->startOfDay()->gte(now()->startOfDay())) {
            return false;
        }

        $this->status = self::STATUS_EXPIRED;
        $this->active_lock = null;
        $this->save();

        return true;
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
        $this->refreshExpiration();

        if (! in_array($to, $this->allowedTransitions(), true)) {
            throw new InvalidArgumentException("Invalid quotation lifecycle transition from {$this->status} to {$to}.");
        }

        if ($to === self::STATUS_SUBMITTED) {
            if ($this->items()->count() < 1) {
                throw new InvalidArgumentException('Quotation must contain at least one item before submission.');
            }
            $this->submitted_at = now();
            $this->active_lock = $this->rfq_distribution_id;
        }

        if ($to === self::STATUS_WITHDRAWN) {
            $this->withdrawn_at = now();
            $this->active_lock = null;
        }

        if ($to === self::STATUS_EXPIRED) {
            $this->active_lock = null;
        }

        $this->status = $to;
        $this->save();
    }

    /**
     * Buyer-facing quotations (never drafts).
     *
     * @param  Builder<Quotation>  $query
     * @return Builder<Quotation>
     */
    public function scopeBuyerVisible(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_SUBMITTED,
            self::STATUS_WITHDRAWN,
            self::STATUS_EXPIRED,
        ]);
    }

    /**
     * Submitted offers that are not past valid_until (evaluated at query time).
     *
     * @param  Builder<Quotation>  $query
     * @return Builder<Quotation>
     */
    public function scopeActiveOffers(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUBMITTED)
            ->where(function ($inner): void {
                $inner->whereNull('valid_until')
                    ->orWhereDate('valid_until', '>=', now()->toDateString());
            });
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(bool $includeSupplier = true): array
    {
        $this->refreshExpiration();
        $this->loadMissing(['items', 'supplierCompany.supplierProfile']);

        $payload = [
            'id' => $this->id,
            'rfq_id' => $this->rfq_id,
            'rfq_distribution_id' => $this->rfq_distribution_id,
            'supplier_company_id' => $this->supplier_company_id,
            'status' => $this->status,
            'currency' => $this->currency,
            'valid_until' => $this->valid_until?->format('Y-m-d'),
            'notes' => $this->notes,
            'shipping_amount' => (string) $this->shipping_amount,
            'tax_amount' => (string) $this->tax_amount,
            'subtotal' => (string) $this->subtotal,
            'total' => (string) $this->total,
            'items' => $this->items->map(fn (QuotationItem $item) => $item->toApiArray())->values()->all(),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'withdrawn_at' => $this->withdrawn_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];

        if ($includeSupplier) {
            $profile = $this->supplierCompany?->supplierProfile;
            $payload['supplier'] = [
                'company_id' => $this->supplier_company_id,
                'company_name' => $this->supplierCompany?->name,
                'display_name' => $profile?->display_name,
            ];
        }

        return $payload;
    }

    /**
     * Neutral comparison row (no scores / ranking).
     *
     * @return array<string, mixed>
     */
    public function toCompareArray(): array
    {
        $data = $this->toApiArray(includeSupplier: true);

        return [
            'id' => $data['id'],
            'status' => $data['status'],
            'currency' => $data['currency'],
            'valid_until' => $data['valid_until'],
            'supplier' => $data['supplier'],
            'shipping_amount' => $data['shipping_amount'],
            'tax_amount' => $data['tax_amount'],
            'subtotal' => $data['subtotal'],
            'total' => $data['total'],
            'items' => collect($data['items'])->map(fn (array $item) => [
                'id' => $item['id'],
                'rfq_item_id' => $item['rfq_item_id'],
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
                'unit_price' => $item['unit_price'],
                'line_total' => $item['line_total'],
            ])->values()->all(),
            'created_at' => $data['created_at'],
        ];
    }
}
