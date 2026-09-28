<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreShipmentRequest extends FormRequest
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
            'carrier' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tracking_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'shipping_method' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'origin_address' => ['sometimes', 'nullable', 'array'],
            'origin_address.contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'origin_address.phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'origin_address.address_line' => ['sometimes', 'nullable', 'string', 'max:500'],
            'origin_address.city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'origin_address.state' => ['sometimes', 'nullable', 'string', 'max:255'],
            'origin_address.region' => ['sometimes', 'nullable', 'string', 'max:255'],
            'origin_address.postal_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'origin_address.country' => ['sometimes', 'nullable', 'string', 'max:255'],
            'destination_address' => ['sometimes', 'nullable', 'array'],
            'destination_address.contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'destination_address.phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'destination_address.address_line' => ['sometimes', 'nullable', 'string', 'max:500'],
            'destination_address.city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'destination_address.state' => ['sometimes', 'nullable', 'string', 'max:255'],
            'destination_address.region' => ['sometimes', 'nullable', 'string', 'max:255'],
            'destination_address.postal_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'destination_address.country' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Spoofing / override fields are ignored by the service.
            'company_id' => ['sometimes'],
            'buyer_company_id' => ['sometimes'],
            'supplier_company_id' => ['sometimes'],
            'tenant_id' => ['sometimes'],
            'user_id' => ['sometimes'],
            'owner_id' => ['sometimes'],
            'purchase_order_id' => ['sometimes'],
            'number' => ['sometimes'],
            'status' => ['sometimes'],
            'items' => ['sometimes'],
        ];
    }
}
