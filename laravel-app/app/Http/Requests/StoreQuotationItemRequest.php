<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuotationItemRequest extends FormRequest
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
            'rfq_item_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'unit' => ['sometimes', 'string', 'max:50'],
            'description' => ['sometimes', 'string', 'max:500'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'line_total' => ['sometimes'],
            'subtotal' => ['sometimes'],
            'total' => ['sometimes'],
        ];
    }
}
