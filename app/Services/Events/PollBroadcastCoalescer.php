<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Events\PollResultsUpdated;
use App\Models\EventPoll;
use Illuminate\Support\Facades\Cache;

/**
 * Rate-limits the broadcast a vote triggers.
 *
 * Broadcasting per vote means a thousand synchronous calls to Reverb in the ten
 * seconds after a question opens, with each voter's request waiting on one of
 * them. A wall that updates once a second is indistinguishable to a room and
 * costs a hundredth as much.
 *
 * This is a leading-edge limiter: the first vote in a window broadcasts and the
 * rest of that window is dropped, so the very last vote before voting stops may
 * not reach the wall on its own. Two things close that gap -- the wall polls as
 * a fallback, and closing or advancing a question broadcasts directly rather
 * than through here, so the final count always lands.
 */
final class PollBroadcastCoalescer
{
    private const int WINDOW_SECONDS = 1;

    /**
     * @return bool true when this call broadcast, false when one was already
     *              sent for this poll inside the current window
     */
    public function schedule(EventPoll $poll): bool
    {
        if (! Cache::add("poll:broadcast:{$poll->id}", true, self::WINDOW_SECONDS)) {
            return false;
        }

        PollResultsUpdated::dispatch($poll);

        return true;
    }
}
