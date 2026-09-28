<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuotationRequest extends FormRequest
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
            'currency' => ['sometimes', 'string', 'size:3'],
            'valid_until' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'shipping_amount' => ['sometimes', 'numeric', 'min:0'],
            'tax_amount' => ['sometimes', 'numeric', 'min:0'],
            'items' => ['sometimes', 'array'],
            'items.*.rfq_item_id' => ['required_with:items', 'integer'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.unit' => ['sometimes', 'string', 'max:50'],
            'items.*.description' => ['sometimes', 'string', 'max:500'],
            'items.*.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Spoofing fields ignored by service.
            'company_id' => ['sometimes'],
            'supplier_id' => ['sometimes'],
            'supplier_company_id' => ['sometimes'],
            'tenant_id' => ['sometimes'],
            'rfq_id' => ['sometimes'],
            'rfq_distribution_id' => ['sometimes'],
            'status' => ['sometimes'],
            'subtotal' => ['sometimes'],
            'total' => ['sometimes'],
            'line_total' => ['sometimes'],
        ];
    }
}
