<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'exchange_rate' => ['sometimes', 'numeric', 'min:1'],
            'notes'         => ['nullable', 'string', 'max:500'],
        ];
    }
}
