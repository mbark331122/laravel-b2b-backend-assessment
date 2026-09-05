<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRfqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'commodity' => ['required', 'string', 'max:255'],
            'specification' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit' => ['required', 'string', 'max:50'],
            'incoterm' => ['required', 'string', 'max:50'],
            'destination' => ['required', 'string', 'max:255'],
        ];
    }
}
