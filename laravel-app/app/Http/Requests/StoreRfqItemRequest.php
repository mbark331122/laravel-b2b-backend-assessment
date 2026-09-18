<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRfqItemRequest extends FormRequest
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
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'item_name' => ['required_without:product_id', 'nullable', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit' => ['required_without:product_id', 'nullable', 'string', 'max:50'],
            'target_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'currency' => ['nullable', 'string', 'size:3', Rule::in(Product::CURRENCIES)],
            'requirements' => ['nullable', 'string'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
