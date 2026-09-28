<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Transaction
 */
class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'type'          => $this->type->value,
            'type_label'    => $this->type->label(),
            'status'        => $this->status->value,
            'status_label'  => $this->status->label(),
            'amount_usd'    => $this->amount_usd,
            'amount_ars'    => $this->amount_ars,
            'exchange_rate' => $this->exchange_rate,
            'notes'         => $this->notes,
            'confirmed_at'  => $this->confirmed_at?->toIso8601String(),
            'created_at'    => $this->created_at->toIso8601String(),
            'created_by_id' => $this->created_by,
            'created_by_name' => $this->whenLoaded('creator', fn () =>
                $this->created_by === $this->user_id ? null : $this->creator?->name
            ),
            'user'          => new UserResource($this->whenLoaded('user')),
            'reviewer'      => new UserResource($this->whenLoaded('reviewer')),
            'creator'       => new UserResource($this->whenLoaded('creator')),
        ];
    }
}
