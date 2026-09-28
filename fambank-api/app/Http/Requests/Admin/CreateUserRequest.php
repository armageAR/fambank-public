<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;

class CreateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('username'))) {
            $this->merge(['username' => Str::lower(trim($this->input('username')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:30', 'alpha_dash', 'unique:users,username'],
            'email'    => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)],
            'role'     => ['required', new Enum(UserRole::class)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'username.required'   => 'El nombre de usuario es obligatorio.',
            'username.unique'     => 'Ya existe un usuario con ese nombre de usuario.',
            'username.alpha_dash' => 'El nombre de usuario solo puede tener letras, números, guiones y guiones bajos.',
            'username.min'        => 'El nombre de usuario debe tener al menos 3 caracteres.',
            'email.unique' => 'Ya existe un usuario con ese email.',
            'role.Illuminate\Validation\Rules\Enum' => 'El rol debe ser admin o member.',
        ];
    }
}
