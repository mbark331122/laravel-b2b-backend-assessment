<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncProductPriceTiersRequest extends FormRequest
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
            'tiers' => ['present', 'array'],
            'tiers.*.min_quantity' => ['required', 'integer', 'min:1'],
            'tiers.*.max_quantity' => ['nullable', 'integer', 'min:1'],
            'tiers.*.price' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'tiers.*.currency' => ['required', 'string', 'size:3', Rule::in(Product::CURRENCIES)],
        ];
    }
}
