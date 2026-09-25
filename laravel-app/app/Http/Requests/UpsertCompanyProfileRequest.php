<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpsertCompanyProfileRequest extends FormRequest
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
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'business_description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tax_identifier' => ['sometimes', 'nullable', 'string', 'max:255'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
            'primary_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'primary_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }
}
