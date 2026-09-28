<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateUserRequest extends FormRequest
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
        $userId = $this->route('user')->id;

        return [
            'name'     => ['sometimes', 'required', 'string', 'max:255'],
            'username' => ['sometimes', 'required', 'string', 'min:3', 'max:30', 'alpha_dash', Rule::unique('users', 'username')->ignore($userId)],
            'email'    => ['sometimes', 'required', 'email', Rule::unique('users', 'email')->ignore($userId)],
            'role'     => ['sometimes', 'required', new Enum(UserRole::class)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'username.unique'     => 'Ya existe un usuario con ese nombre de usuario.',
            'username.alpha_dash' => 'El nombre de usuario solo puede tener letras, números, guiones y guiones bajos.',
            'username.min'        => 'El nombre de usuario debe tener al menos 3 caracteres.',
            'email.unique'        => 'Ya existe un usuario con ese email.',
            'email.email'         => 'El email no es válido.',
        ];
    }
}
