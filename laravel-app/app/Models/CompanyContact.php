<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'name',
    'job_title',
    'email',
    'phone',
    'contact_type',
    'is_active',
])]
class CompanyContact extends Model
{
    public const TYPE_GENERAL = 'general';

    public const TYPE_BILLING = 'billing';

    public const TYPE_SHIPPING = 'shipping';

    public const TYPE_OTHER = 'other';

    /**
     * @return list<string>
     */
    public static function contactTypes(): array
    {
        return [
            self::TYPE_GENERAL,
            self::TYPE_BILLING,
            self::TYPE_SHIPPING,
            self::TYPE_OTHER,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            'job_title' => $this->job_title,
            'email' => $this->email,
            'phone' => $this->phone,
            'contact_type' => $this->contact_type,
            'is_active' => (bool) $this->is_active,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
