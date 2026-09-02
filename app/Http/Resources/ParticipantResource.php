<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ParticipantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'status' => $this->status->value,
            'joined_at' => $this->joined_at?->toIso8601String(),
            'left_at' => $this->left_at?->toIso8601String(),
            'can_record' => $this->can_record,
            'is_mic_enabled' => $this->is_mic_enabled,
            'is_chairperson' => $this->is_chairperson,
            'chair_type' => $this->chair_type?->value,
        ];
    }
}
