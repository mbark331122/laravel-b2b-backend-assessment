<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseOrderRequest extends FormRequest
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
            // Spoofing / override fields are ignored by the service.
            'company_id' => ['sometimes'],
            'buyer_company_id' => ['sometimes'],
            'supplier_company_id' => ['sometimes'],
            'tenant_id' => ['sometimes'],
            'user_id' => ['sometimes'],
            'owner_id' => ['sometimes'],
            'rfq_id' => ['sometimes'],
            'quotation_id' => ['sometimes'],
            'negotiation_id' => ['sometimes'],
            'accepted_offer_id' => ['sometimes'],
            'number' => ['sometimes'],
            'status' => ['sometimes'],
            'subtotal' => ['sometimes'],
            'total' => ['sometimes'],
            'shipping_amount' => ['sometimes'],
            'tax_amount' => ['sometimes'],
            'currency' => ['sometimes'],
        ];
    }
}
