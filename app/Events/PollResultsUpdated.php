<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\EventPoll;
use App\Services\Events\PollResults;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Moves the bars on the projector the moment somebody votes.
 *
 * Deliberately a public channel. The screen this feeds is pointed at a room of
 * people and is opened by an AV desk that holds no login, so there is nobody to
 * authorise. What travels is therefore counts only -- never a respondent, a
 * name, a token or an answer attributable to anyone.
 */
final class PollResultsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public EventPoll $poll) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('event.'.$this->poll->event_id.'.poll')];
    }

    public function broadcastAs(): string
    {
        return 'PollResultsUpdated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return app(PollResults::class)->forDisplay($this->poll);
    }
}
