<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([])]
class PurchaseOrderParty extends Model
{
    public const ROLE_BUYER = 'buyer';

    public const ROLE_SUPPLIER = 'supplier';

    public const ROLE_INTERMEDIARY = 'intermediary';

    public const ROLE_INTERNAL_OPERATOR = 'internal_operator';

    /**
     * @var list<string>
     */
    public const ROLES = [
        self::ROLE_BUYER,
        self::ROLE_SUPPLIER,
        self::ROLE_INTERMEDIARY,
        self::ROLE_INTERNAL_OPERATOR,
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REMOVED = 'removed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'joined_at' => 'datetime',
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
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasOne<IntermediaryCommission, $this>
     */
    public function commission(): HasOne
    {
        return $this->hasOne(IntermediaryCommission::class);
    }

    /**
     * Grants where this party is the viewer.
     *
     * @return HasMany<PartyIdentityGrant, $this>
     */
    public function identityGrantsAsViewer(): HasMany
    {
        return $this->hasMany(PartyIdentityGrant::class, 'viewer_party_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isIntermediary(): bool
    {
        return $this->role === self::ROLE_INTERMEDIARY;
    }

    /**
     * @param  Builder<PurchaseOrderParty>  $query
     * @return Builder<PurchaseOrderParty>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
