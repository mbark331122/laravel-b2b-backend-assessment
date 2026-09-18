<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

#[Fillable([])]
class RfqDistribution extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_WITHDRAWN = 'withdrawn';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SENT,
        self::STATUS_WITHDRAWN,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'distributed_at' => 'datetime',
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
     * @return BelongsTo<Company, $this>
     */
    public function supplierCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'supplier_company_id');
    }

    /**
     * @return BelongsTo<SupplierProfile, $this>
     */
    public function supplierProfile(): BelongsTo
    {
        return $this->belongsTo(SupplierProfile::class, 'supplier_profile_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_SENT], true);
    }

    public function withdraw(): void
    {
        if ($this->status === self::STATUS_WITHDRAWN) {
            throw new InvalidArgumentException('Distribution is already withdrawn.');
        }

        if (! $this->isActive()) {
            throw new InvalidArgumentException('Only active distributions can be withdrawn.');
        }

        $this->status = self::STATUS_WITHDRAWN;
        $this->withdrawn_at = now();
        $this->save();
    }

    public function markSent(): void
    {
        $this->status = self::STATUS_SENT;
        $this->distributed_at = $this->distributed_at ?? now();
        $this->withdrawn_at = null;
        $this->save();
    }

    /**
     * @param  Builder<RfqDistribution>  $query
     * @return Builder<RfqDistribution>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_SENT]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $this->loadMissing(['supplierProfile', 'supplierCompany']);

        return [
            'id' => $this->id,
            'rfq_id' => $this->rfq_id,
            'supplier_company_id' => $this->supplier_company_id,
            'supplier_profile_id' => $this->supplier_profile_id,
            'status' => $this->status,
            'distributed_at' => $this->distributed_at?->toISOString(),
            'withdrawn_at' => $this->withdrawn_at?->toISOString(),
            'supplier_profile' => $this->supplierProfile?->toApiArray(),
            'supplier_company' => $this->supplierCompany ? [
                'id' => $this->supplierCompany->id,
                'name' => $this->supplierCompany->name,
                'is_supplier' => $this->supplierCompany->isSupplier(),
            ] : null,
        ];
    }
}
