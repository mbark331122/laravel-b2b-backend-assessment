<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRfqDistributionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier_profile_ids' => ['required', 'array', 'min:1'],
            'supplier_profile_ids.*' => ['integer', 'distinct'],
            // Spoofing fields are ignored; identity comes from auth + eligibility checks.
            'company_id' => ['sometimes'],
            'buyer_company_id' => ['sometimes'],
            'supplier_company_id' => ['sometimes'],
            'supplier_id' => ['sometimes'],
            'tenant_id' => ['sometimes'],
        ];
    }
}
