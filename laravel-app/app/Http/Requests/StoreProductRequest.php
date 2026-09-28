<?php

namespace App\Http\Requests;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'brand_id' => [
                'nullable',
                'integer',
                Rule::exists('brands', 'id')->where(fn ($query) => $query->where('status', Brand::STATUS_ACTIVE)),
            ],
            'unit' => ['required', 'string', 'max:50'],
            'minimum_order_quantity' => ['required', 'integer', 'min:1'],
            'maximum_order_quantity' => ['nullable', 'integer', 'min:1'],
            'quantity_increment' => ['sometimes', 'integer', 'min:1'],
            'wholesale_price' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'currency' => ['required', 'string', 'size:3', Rule::in(Product::CURRENCIES)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $moq = (int) $this->input('minimum_order_quantity');
            $max = $this->input('maximum_order_quantity');
            $increment = (int) ($this->input('quantity_increment') ?? 1);

            if ($max !== null && $max !== '' && (int) $max < $moq) {
                $validator->errors()->add('maximum_order_quantity', 'Maximum order quantity must be greater than or equal to MOQ.');
            }

            if ($increment < 1) {
                $validator->errors()->add('quantity_increment', 'Quantity increment must be at least 1.');
            }

            if ($max !== null && $max !== '' && $increment > 0 && ((int) $max - $moq) % $increment !== 0) {
                $validator->errors()->add('maximum_order_quantity', 'Maximum order quantity must align with MOQ and quantity increment.');
            }
        });
    }
}
