<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
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
        /** @var Product $product */
        $product = $this->route('product');
        $companyId = $product->company_id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'sku' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('products', 'sku')
                    ->where(fn ($query) => $query->where('company_id', $companyId))
                    ->ignore($product->id),
            ],
            'description' => ['nullable', 'string'],
            'product_category_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('product_categories', 'id')->where(fn ($query) => $query->where('status', ProductCategory::STATUS_ACTIVE)),
            ],
            'unit' => ['sometimes', 'required', 'string', 'max:50'],
            'minimum_order_quantity' => ['sometimes', 'required', 'integer', 'min:1'],
            'wholesale_price' => ['sometimes', 'required', 'numeric', 'min:0', 'decimal:0,2'],
            'currency' => ['sometimes', 'required', 'string', 'size:3', Rule::in(Product::CURRENCIES)],
            'status' => ['sometimes', 'required', 'string', Rule::in([Product::STATUS_ACTIVE, Product::STATUS_INACTIVE])],
        ];
    }
}
