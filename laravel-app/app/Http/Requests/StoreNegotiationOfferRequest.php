<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreNegotiationOfferRequest extends FormRequest
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
            'valid_until' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'shipping_amount' => ['sometimes', 'numeric', 'min:0'],
            'tax_amount' => ['sometimes', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.rfq_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.unit' => ['sometimes', 'string', 'max:50'],
            'items.*.description' => ['sometimes', 'string', 'max:500'],
            'items.*.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'side' => ['sometimes'],
            'party' => ['sometimes'],
            'company_id' => ['sometimes'],
            'supplier_id' => ['sometimes'],
            'buyer_id' => ['sometimes'],
            'buyer_company_id' => ['sometimes'],
            'supplier_company_id' => ['sometimes'],
            'tenant_id' => ['sometimes'],
            'user_id' => ['sometimes'],
            'subtotal' => ['sometimes'],
            'total' => ['sometimes'],
            'line_total' => ['sometimes'],
            'sequence' => ['sometimes'],
            'status' => ['sometimes'],
        ];
    }
}
