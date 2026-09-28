<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Auto-edición del usuario autenticado: solo email y contraseña.
 * Para cambiar la contraseña debe acreditar la actual.
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email'            => ['sometimes', 'required', 'email', Rule::unique('users', 'email')->ignore($this->user()->id)],
            'password'         => ['sometimes', 'required', 'string', Password::min(8), 'confirmed'],
            'current_password' => ['required_with:password', 'current_password'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.unique'                       => 'Ya existe un usuario con ese email.',
            'email.email'                        => 'El email no es válido.',
            'password.confirmed'                 => 'Las contraseñas no coinciden.',
            'current_password.required_with'     => 'Ingresá tu contraseña actual para cambiarla.',
            'current_password.current_password'  => 'La contraseña actual no es correcta.',
        ];
    }
}
