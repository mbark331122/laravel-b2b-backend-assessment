<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'legal_name',
    'business_description',
    'registration_number',
    'tax_identifier',
    'website',
    'primary_email',
    'primary_phone',
])]
class CompanyProfile extends Model
{
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
            'legal_name' => $this->legal_name,
            'business_description' => $this->business_description,
            'registration_number' => $this->registration_number,
            'tax_identifier' => $this->tax_identifier,
            'website' => $this->website,
            'primary_email' => $this->primary_email,
            'primary_phone' => $this->primary_phone,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
