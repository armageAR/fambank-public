<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    /**
     * Representación del usuario para la API.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'username'      => $this->username,
            'email'         => $this->email,
            'role'          => $this->role->value,
            'balance_usd'   => $this->balance_usd,
            'active'        => $this->active,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
