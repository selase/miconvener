<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\EventSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class SessionAttendanceUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public EventSession $session,
        public string $action = 'check_in',
        public ?string $attendeeName = null,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('event.'.$this->session->event_id.'.sessions'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'SessionAttendanceUpdated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->session->id,
            'title' => $this->session->title,
            'location' => $this->session->location,
            'capacity' => $this->session->capacity,
            'live_headcount' => $this->session->liveHeadcount(),
            'occupancy_percentage' => $this->session->occupancyPercentage(),
            'is_room_full' => $this->session->isRoomFull(),
            'action' => $this->action,
            'attendee_name' => $this->attendeeName,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
