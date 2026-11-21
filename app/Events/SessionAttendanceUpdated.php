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
        return ['refresh' => true];
    }
}
