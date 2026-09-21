<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreNegotiationRequest extends FormRequest
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
            'valid_until' => ['sometimes', 'nullable', 'date'],
            'company_id' => ['sometimes'],
            'buyer_company_id' => ['sometimes'],
            'supplier_company_id' => ['sometimes'],
            'tenant_id' => ['sometimes'],
            'user_id' => ['sometimes'],
            'owner_id' => ['sometimes'],
            'quotation_id' => ['sometimes'],
            'rfq_id' => ['sometimes'],
            'status' => ['sometimes'],
        ];
    }
}
