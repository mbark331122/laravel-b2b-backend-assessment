<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePartyIdentityGrantRequest extends FormRequest
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
            'viewer_party_id' => ['required', 'integer'],
            'visible_party_id' => ['required', 'integer'],
            'company_id' => ['sometimes'],
            'role' => ['sometimes'],
            'visibility' => ['sometimes'],
            'is_internal' => ['sometimes'],
        ];
    }
}
