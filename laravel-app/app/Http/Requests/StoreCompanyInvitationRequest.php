<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyInvitationRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::in([
                Role::COMPANY_USER,
                Role::SUPPLIER_USER,
                Role::INTERMEDIARY_USER,
            ])],
            'expires_in_days' => ['sometimes', 'integer', 'min:1', 'max:30'],
        ];
    }
}
