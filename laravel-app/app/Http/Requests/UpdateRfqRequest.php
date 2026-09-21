<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\Rfq;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateRfqRequest extends FormRequest
{
    public function authorize(): bool
    {
        $rfq = $this->route('rfq');

        if (! $rfq instanceof Rfq) {
            return false;
        }

        Gate::authorize('update', $rfq);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'commodity' => ['sometimes', 'required', 'string', 'max:255'],
            'specification' => ['sometimes', 'required', 'string', 'max:255'],
            'quantity' => ['sometimes', 'required', 'integer', 'min:1'],
            'unit' => ['sometimes', 'required', 'string', 'max:50'],
            'incoterm' => ['sometimes', 'required', 'string', 'max:50'],
            'destination' => ['sometimes', 'required', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3', Rule::in(Product::CURRENCIES)],
            'required_by_date' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }
}
