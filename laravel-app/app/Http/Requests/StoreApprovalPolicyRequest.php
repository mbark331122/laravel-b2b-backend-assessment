<?php

namespace App\Http\Requests;

use App\Models\ApprovalPolicy;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApprovalPolicyRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'approval_type' => ['required', 'string', Rule::in(ApprovalPolicy::types())],
            'is_active' => ['sometimes', 'boolean'],
            'min_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.step_order' => ['sometimes', 'integer', 'min:1'],
            'steps.*.approver_role' => ['required', 'string', Rule::in([
                Role::COMPANY_USER,
                Role::SUPPLIER_USER,
                Role::INTERMEDIARY_USER,
            ])],
        ];
    }
}
