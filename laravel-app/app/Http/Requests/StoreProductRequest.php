<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
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
        $companyId = $this->user()?->company_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => [
                'required',
                'string',
                'max:100',
                Rule::unique('products', 'sku')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'description' => ['nullable', 'string'],
            'product_category_id' => [
                'required',
                'integer',
                Rule::exists('product_categories', 'id')->where(fn ($query) => $query->where('status', ProductCategory::STATUS_ACTIVE)),
            ],
            'unit' => ['required', 'string', 'max:50'],
            'minimum_order_quantity' => ['required', 'integer', 'min:1'],
            'wholesale_price' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'currency' => ['required', 'string', 'size:3', Rule::in(Product::CURRENCIES)],
            'status' => ['sometimes', 'string', Rule::in([Product::STATUS_ACTIVE, Product::STATUS_INACTIVE])],
        ];
    }
}
