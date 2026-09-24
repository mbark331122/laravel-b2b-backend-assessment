<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

#[Fillable([])]
class Negotiation extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUS_EXPIRED = 'expired';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_ACCEPTED,
        self::STATUS_REJECTED,
        self::STATUS_WITHDRAWN,
        self::STATUS_EXPIRED,
    ];

    /**
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_OPEN => [
            self::STATUS_ACCEPTED,
            self::STATUS_REJECTED,
            self::STATUS_WITHDRAWN,
            self::STATUS_EXPIRED,
        ],
        self::STATUS_ACCEPTED => [],
        self::STATUS_REJECTED => [],
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
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
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
     * @return BelongsTo<Quotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
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
     * @return BelongsTo<NegotiationOffer, $this>
     */
    public function acceptedOffer(): BelongsTo
    {
        return $this->belongsTo(NegotiationOffer::class, 'accepted_offer_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function acceptedByCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'accepted_by_company_id');
    }

    /**
     * @return HasMany<NegotiationOffer, $this>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(NegotiationOffer::class)->orderBy('sequence')->orderBy('id');
    }

    public function isOpen(): bool
    {
        $this->refreshExpiration();

        return $this->status === self::STATUS_OPEN;
    }

    public function refreshExpiration(): bool
    {
        if ($this->status !== self::STATUS_OPEN) {
            return false;
        }

        if ($this->valid_until === null) {
            return false;
        }

        if ($this->valid_until->copy()->startOfDay()->gte(now()->startOfDay())) {
            return false;
        }

        $beforeStatus = $this->status;

        $this->status = self::STATUS_EXPIRED;
        $this->active_lock = null;
        $this->save();

        app(\App\Services\AuditLogger::class)->record(
            \App\Models\AuditLog::NEGOTIATION_EXPIRED,
            $this,
            $this->buyer_company_id,
            before: ['status' => $beforeStatus],
            after: ['status' => self::STATUS_EXPIRED],
        );

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
            throw new InvalidArgumentException("Invalid negotiation lifecycle transition from {$this->status} to {$to}.");
        }

        if ($to === self::STATUS_REJECTED) {
            $this->rejected_at = now();
            $this->active_lock = null;
        }

        if ($to === self::STATUS_WITHDRAWN) {
            $this->withdrawn_at = now();
            $this->active_lock = null;
        }

        if ($to === self::STATUS_EXPIRED) {
            $this->active_lock = null;
        }

        if ($to === self::STATUS_ACCEPTED) {
            $this->active_lock = null;
        }

        $this->status = $to;
        $this->save();
    }

    public function participantSide(User $user): ?string
    {
        if ($user->company_id === null) {
            return null;
        }

        if ((int) $user->company_id === (int) $this->buyer_company_id) {
            return NegotiationOffer::SIDE_BUYER;
        }

        if ((int) $user->company_id === (int) $this->supplier_company_id) {
            return NegotiationOffer::SIDE_SUPPLIER;
        }

        return null;
    }

    public function isParticipant(User $user): bool
    {
        return $this->participantSide($user) !== null || $user->isAdmin();
    }

    /**
     * Whose turn it is based on the latest offer side (opposite party).
     */
    public function nextTurnSide(): ?string
    {
        $latest = $this->offers()->reorder()->orderByDesc('sequence')->orderByDesc('id')->first();

        if ($latest === null) {
            return NegotiationOffer::SIDE_SUPPLIER;
        }

        return $latest->side === NegotiationOffer::SIDE_BUYER
            ? NegotiationOffer::SIDE_SUPPLIER
            : NegotiationOffer::SIDE_BUYER;
    }

    /**
     * @param  Builder<Negotiation>  $query
     * @return Builder<Negotiation>
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
    public function toApiArray(bool $includeOffers = false): array
    {
        $this->refreshExpiration();
        $this->loadMissing([
            'buyerCompany',
            'supplierCompany.supplierProfile',
            'acceptedOffer.items',
            'acceptedByCompany',
        ]);

        $payload = [
            'id' => $this->id,
            'rfq_id' => $this->rfq_id,
            'rfq_distribution_id' => $this->rfq_distribution_id,
            'quotation_id' => $this->quotation_id,
            'status' => $this->status,
            'valid_until' => $this->valid_until?->format('Y-m-d'),
            'next_turn_side' => $this->status === self::STATUS_OPEN ? $this->nextTurnSide() : null,
            'buyer_company' => [
                'id' => $this->buyer_company_id,
                'name' => $this->buyerCompany?->name,
            ],
            'supplier_company' => [
                'id' => $this->supplier_company_id,
                'name' => $this->supplierCompany?->name,
                'display_name' => $this->supplierCompany?->supplierProfile?->display_name,
            ],
            'accepted_offer_id' => $this->accepted_offer_id,
            'accepted_at' => $this->accepted_at?->toISOString(),
            'accepted_by_company' => $this->acceptedByCompany ? [
                'id' => $this->acceptedByCompany->id,
                'name' => $this->acceptedByCompany->name,
            ] : null,
            'accepted_offer' => $this->acceptedOffer?->toApiArray(),
            'rejected_at' => $this->rejected_at?->toISOString(),
            'withdrawn_at' => $this->withdrawn_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];

        if ($includeOffers) {
            $this->loadMissing('offers.items');
            $payload['offers'] = $this->offers->map(fn (NegotiationOffer $offer) => $offer->toApiArray())->values()->all();
        }

        return $payload;
    }
}
