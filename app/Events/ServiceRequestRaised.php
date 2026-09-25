<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\EventServiceRequest;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Announces a request the moment it is raised.
 *
 * Broadcast now rather than queued: someone has asked for water, or for first
 * aid, and a request that waits its turn behind the mail queue is a request
 * that arrives after the person needed it.
 */
final class ServiceRequestRaised implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public EventServiceRequest $serviceRequest) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('event.'.$this->serviceRequest->event_id.'.service-requests'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ServiceRequestRaised';
    }

    /**
     * The same shape the console renders when it fetches for itself, so a
     * request looks identical however it arrived.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->serviceRequest->consolePayload();
    }
}
