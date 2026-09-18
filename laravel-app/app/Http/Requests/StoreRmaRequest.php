<?php

namespace App\Http\Requests;

use App\Models\Rma;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRmaRequest extends FormRequest
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
            'reason' => ['required', 'string', Rule::in(Rma::REASONS)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.shipment_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.reason' => ['sometimes', 'nullable', 'string', Rule::in(Rma::REASONS)],
            'items.*.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Spoofing / override fields are ignored by the service.
            'company_id' => ['sometimes'],
            'buyer_company_id' => ['sometimes'],
            'supplier_company_id' => ['sometimes'],
            'tenant_id' => ['sometimes'],
            'user_id' => ['sometimes'],
            'owner_id' => ['sometimes'],
            'shipment_id' => ['sometimes'],
            'purchase_order_id' => ['sometimes'],
            'supplier_id' => ['sometimes'],
            'product_id' => ['sometimes'],
            'number' => ['sometimes'],
            'status' => ['sometimes'],
            'requested_by_user_id' => ['sometimes'],
        ];
    }
}
