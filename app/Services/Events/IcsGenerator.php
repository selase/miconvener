<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventSession;
use Illuminate\Support\Collection;

final class IcsGenerator
{
    /**
     * @param  Collection<int, EventSession>  $sessions
     */
    public static function forSessions(Event $event, Collection $sessions): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//MiConvener//'.self::escape($event->name).'//EN',
            'CALSCALE:GREGORIAN',
        ];

        foreach ($sessions as $session) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.$session->id.'@miconvener';
            $lines[] = 'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z');
            $lines[] = 'DTSTART:'.$session->starts_at->clone()->utc()->format('Ymd\THis\Z');
            $lines[] = 'DTEND:'.$session->ends_at->clone()->utc()->format('Ymd\THis\Z');
            $lines[] = 'SUMMARY:'.self::escape($session->title);
            if ($session->location) {
                $lines[] = 'LOCATION:'.self::escape($session->location);
            }
            if ($session->description) {
                $lines[] = 'DESCRIPTION:'.self::escape($session->description);
            }
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines)."\r\n";
    }

    private static function escape(string $value): string
    {
        return str_replace(
            ["\\", "\n", ',', ';'],
            ["\\\\", '\\n', '\\,', '\\;'],
            $value,
        );
    }
}
