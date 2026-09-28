<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeliveryConfirmationRequest extends FormRequest
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
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Spoofing / override fields are ignored by the service.
            'company_id' => ['sometimes'],
            'buyer_company_id' => ['sometimes'],
            'supplier_company_id' => ['sometimes'],
            'tenant_id' => ['sometimes'],
            'user_id' => ['sometimes'],
            'owner_id' => ['sometimes'],
            'confirmed_by_user_id' => ['sometimes'],
            'shipment_id' => ['sometimes'],
            'purchase_order_id' => ['sometimes'],
            'number' => ['sometimes'],
            'status' => ['sometimes'],
            'confirmed_at' => ['sometimes'],
        ];
    }
}
