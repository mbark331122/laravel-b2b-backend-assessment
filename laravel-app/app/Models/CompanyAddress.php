<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'type',
    'label',
    'contact_name',
    'phone',
    'address_line',
    'city',
    'state',
    'region',
    'postal_code',
    'country',
    'is_default',
])]
class CompanyAddress extends Model
{
    public const TYPE_REGISTERED = 'registered';

    public const TYPE_BILLING = 'billing';

    public const TYPE_SHIPPING = 'shipping';

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_REGISTERED,
            self::TYPE_BILLING,
            self::TYPE_SHIPPING,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
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
     * Snapshot-friendly address payload for later commercial documents (not applied here).
     *
     * @return array<string, mixed>
     */
    public function toSnapshotArray(): array
    {
        return [
            'contact_name' => $this->contact_name,
            'phone' => $this->phone,
            'address_line' => $this->address_line,
            'city' => $this->city,
            'state' => $this->state,
            'region' => $this->region,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'type' => $this->type,
            'label' => $this->label,
            'contact_name' => $this->contact_name,
            'phone' => $this->phone,
            'address_line' => $this->address_line,
            'city' => $this->city,
            'state' => $this->state,
            'region' => $this->region,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'is_default' => (bool) $this->is_default,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
