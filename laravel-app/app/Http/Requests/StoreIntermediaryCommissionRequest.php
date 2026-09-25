<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreIntermediaryCommissionRequest extends FormRequest
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
            'purchase_order_party_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'company_id' => ['sometimes'],
            'commission' => ['sometimes'],
            'is_internal' => ['sometimes'],
            'status' => ['sometimes'],
        ];
    }
}
