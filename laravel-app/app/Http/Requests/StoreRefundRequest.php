<?php

namespace App\Http\Requests;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRefundRequest extends FormRequest
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
            'method' => ['sometimes', 'nullable', 'string', Rule::in(Payment::METHODS)],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Spoofing / override fields are ignored by the service.
            'company_id' => ['sometimes'],
            'buyer_company_id' => ['sometimes'],
            'supplier_company_id' => ['sometimes'],
            'tenant_id' => ['sometimes'],
            'user_id' => ['sometimes'],
            'owner_id' => ['sometimes'],
            'credit_note_id' => ['sometimes'],
            'invoice_id' => ['sometimes'],
            'payment_id' => ['sometimes'],
            'rma_id' => ['sometimes'],
            'purchase_order_id' => ['sometimes'],
            'number' => ['sometimes'],
            'status' => ['sometimes'],
            'amount' => ['sometimes'],
            'currency' => ['sometimes'],
            'processed_at' => ['sometimes'],
            'failed_at' => ['sometimes'],
            'cancelled_at' => ['sometimes'],
        ];
    }
}
