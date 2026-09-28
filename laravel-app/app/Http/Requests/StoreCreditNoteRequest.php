<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCreditNoteRequest extends FormRequest
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
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Spoofing / override fields are ignored by the service.
            'company_id' => ['sometimes'],
            'buyer_company_id' => ['sometimes'],
            'supplier_company_id' => ['sometimes'],
            'tenant_id' => ['sometimes'],
            'user_id' => ['sometimes'],
            'owner_id' => ['sometimes'],
            'rma_id' => ['sometimes'],
            'invoice_id' => ['sometimes'],
            'purchase_order_id' => ['sometimes'],
            'payment_id' => ['sometimes'],
            'number' => ['sometimes'],
            'status' => ['sometimes'],
            'subtotal' => ['sometimes'],
            'tax_amount' => ['sometimes'],
            'total' => ['sometimes'],
            'amount' => ['sometimes'],
            'currency' => ['sometimes'],
            'issued_at' => ['sometimes'],
            'cancelled_at' => ['sometimes'],
            'voided_at' => ['sometimes'],
        ];
    }
}
