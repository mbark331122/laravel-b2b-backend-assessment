<?php

namespace App\Http\Requests;

use App\Models\Rfq;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

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
