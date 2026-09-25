<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRfqRequest extends FormRequest
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
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'commodity' => ['required', 'string', 'max:255'],
            'specification' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit' => ['required', 'string', 'max:50'],
            'incoterm' => ['required', 'string', 'max:50'],
            'destination' => ['required', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3', Rule::in(Product::CURRENCIES)],
            'required_by_date' => ['nullable', 'date', 'after_or_equal:today'],
            'items' => ['sometimes', 'array'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.item_name' => ['required_without:items.*.product_id', 'nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit' => ['required_without:items.*.product_id', 'nullable', 'string', 'max:50'],
            'items.*.target_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'items.*.currency' => ['nullable', 'string', 'size:3', Rule::in(Product::CURRENCIES)],
            'items.*.requirements' => ['nullable', 'string'],
        ];
    }
}
