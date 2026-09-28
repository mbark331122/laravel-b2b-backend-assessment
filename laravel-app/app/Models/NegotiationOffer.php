<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([])]
class NegotiationOffer extends Model
{
    public const SIDE_BUYER = 'buyer';

    public const SIDE_SUPPLIER = 'supplier';

    public const STATUS_PROPOSED = 'proposed';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_ACCEPTED = 'accepted';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'valid_until' => 'date',
            'shipping_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
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
     * @return BelongsTo<Company, $this>
     */
    public function createdByCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'created_by_company_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<NegotiationOfferItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(NegotiationOfferItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function isExpired(): bool
    {
        if ($this->valid_until === null) {
            return false;
        }

        return $this->valid_until->copy()->startOfDay()->lt(now()->startOfDay());
    }

    public function isAcceptable(): bool
    {
        return $this->status === self::STATUS_PROPOSED && ! $this->isExpired();
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $this->loadMissing(['items', 'createdByCompany', 'createdByUser']);

        return [
            'id' => $this->id,
            'negotiation_id' => $this->negotiation_id,
            'sequence' => $this->sequence,
            'side' => $this->side,
            'status' => $this->status,
            'currency' => $this->currency,
            'valid_until' => $this->valid_until?->format('Y-m-d'),
            'notes' => $this->notes,
            'shipping_amount' => (string) $this->shipping_amount,
            'tax_amount' => (string) $this->tax_amount,
            'subtotal' => (string) $this->subtotal,
            'total' => (string) $this->total,
            'created_by_company' => [
                'id' => $this->created_by_company_id,
                'name' => $this->createdByCompany?->name,
            ],
            'created_by_user' => [
                'id' => $this->created_by_user_id,
                'name' => $this->createdByUser?->name,
            ],
            'items' => $this->items->map(fn (NegotiationOfferItem $item) => $item->toApiArray())->values()->all(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
