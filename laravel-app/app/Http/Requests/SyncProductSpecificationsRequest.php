<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncProductSpecificationsRequest extends FormRequest
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
            'specifications' => ['present', 'array'],
            'specifications.*.name' => ['required', 'string', 'max:255', 'distinct'],
            'specifications.*.value' => ['required', 'string', 'max:255'],
            'specifications.*.unit' => ['nullable', 'string', 'max:50'],
            'specifications.*.sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
