<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DiscussionLogEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sort_order' => $this->sort_order,
            'timestamp_seconds' => $this->timestamp_seconds ? (float) $this->timestamp_seconds : null,
            'speaker_name' => $this->speaker_name,
            'statement' => $this->statement,
            'notes' => $this->notes,
            'agenda_item' => $this->whenLoaded('agendaItem', fn (): array => [
                'id' => $this->agendaItem->id,
                'title' => $this->agendaItem->title,
            ]),
            'participant' => $this->whenLoaded('participant', fn (): array => [
                'id' => $this->participant->id,
                'name' => $this->participant->user?->name ?? $this->participant->display_name ?? 'Unknown',
            ]),
            'transcript_segment_id' => $this->transcript_segment_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
