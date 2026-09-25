<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseOrderPartyRequest extends FormRequest
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
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'sequence' => ['sometimes', 'nullable', 'integer', 'min:1'],
            // Client-supplied role/visibility/ownership/commission fields are ignored by the service.
            'role' => ['sometimes'],
            'purchase_order_id' => ['sometimes'],
            'user_id' => ['sometimes'],
            'owner_id' => ['sometimes'],
            'buyer_company_id' => ['sometimes'],
            'supplier_company_id' => ['sometimes'],
            'tenant_id' => ['sometimes'],
            'status' => ['sometimes'],
            'visibility' => ['sometimes'],
            'commission' => ['sometimes'],
            'is_internal' => ['sometimes'],
        ];
    }
}
