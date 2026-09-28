<?php

namespace App\Http\Requests;

use App\Enums\TransactionType;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class CreateTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isAdmin = $this->user()?->role === UserRole::Admin;

        return [
            // Solo el admin opera sobre otro usuario y define el TC.
            // Para members ambos campos se ignoran: el TC se obtiene del servidor.
            // El destino debe ser un member activo (no otro admin ni un desactivado).
            'user_id'       => $isAdmin
                ? ['required', 'integer', Rule::exists('users', 'id')->where('role', UserRole::Member->value)->where('active', true)]
                : ['nullable'],
            'exchange_rate' => $isAdmin ? ['required', 'numeric', 'min:1'] : ['nullable'],
            'type'          => ['required', new Enum(TransactionType::class)],
            'amount_ars'    => ['required', 'numeric', 'min:1'],
            'notes'         => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required'          => 'El tipo de operación es obligatorio.',
            'amount_ars.required'    => 'El importe en pesos es obligatorio.',
            'amount_ars.numeric'     => 'El importe en pesos debe ser un número.',
            'amount_ars.min'         => 'El importe en pesos debe ser mayor a cero.',
            'exchange_rate.required' => 'El tipo de cambio es obligatorio.',
            'exchange_rate.min'      => 'El tipo de cambio debe ser mayor a cero.',
            'user_id.required'       => 'Indicá el miembro de la transacción.',
            'user_id.exists'         => 'El usuario seleccionado no existe, no es un miembro o está desactivado.',
        ];
    }
}
