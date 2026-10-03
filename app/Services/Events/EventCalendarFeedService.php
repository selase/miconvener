<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventSession;
use Carbon\CarbonInterface;

final class EventCalendarFeedService
{
    /**
     * Generate RFC 5545 compliant iCalendar (.ics) string for an event.
     */
    public function generate(Event $event): string
    {
        $sessions = $event->sessions()
            ->with('speakers')
            ->where('occurrence_status', '!=', EventSession::STATUS_CANCELLED)
            ->orderBy('starts_at')
            ->get();

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//MiConvener//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->escapeText($event->name),
            'X-WR-TIMEZONE:'.($event->timezone ?: 'UTC'),
        ];

        if ($event->description) {
            $lines[] = 'X-WR-CALDESC:'.$this->escapeText($event->description);
        }

        if ($sessions->isEmpty()) {
            // Include master event as single VEVENT if no sessions exist
            if ($event->starts_at && $event->ends_at) {
                $lines = array_merge($lines, $this->buildVEvent(
                    uid: "event-{$event->id}@miconvener.com",
                    summary: $event->name,
                    description: $event->description,
                    location: $event->address,
                    startsAt: $event->starts_at,
                    endsAt: $event->ends_at,
                    url: url("/e/{$event->slug}")
                ));
            }
        } else {
            foreach ($sessions as $session) {
                $speakers = $session->speakers->pluck('name')->implode(', ');
                $descParts = [];
                if ($session->description) {
                    $descParts[] = $session->description;
                }
                if ($speakers) {
                    $descParts[] = "Speaker(s): {$speakers}";
                }
                if ($session->notes) {
                    $descParts[] = "Notes:\n".$session->notes;
                }
                if ($session->presentation_url) {
                    $descParts[] = "Presentation Slides: {$session->presentation_url}";
                }
                $descParts[] = 'Event: '.url("/e/{$event->slug}");

                $lines = array_merge($lines, $this->buildVEvent(
                    uid: "session-{$session->id}@miconvener.com",
                    summary: "{$event->name}: {$session->title}",
                    description: implode("\n\n", $descParts),
                    location: $session->location ?: $event->address,
                    startsAt: $session->starts_at,
                    endsAt: $session->ends_at,
                    url: $session->presentation_url ?: url("/e/{$event->slug}")
                ));
            }
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines)."\r\n";
    }

    /**
     * @return array<int, string>
     */
    private function buildVEvent(
        string $uid,
        string $summary,
        ?string $description,
        ?string $location,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        string $url
    ): array {
        $now = now()->format('Ymd\THis\Z');
        $startStr = $startsAt->copy()->setTimezone('UTC')->format('Ymd\THis\Z');
        $endStr = $endsAt->copy()->setTimezone('UTC')->format('Ymd\THis\Z');

        $eventLines = [
            'BEGIN:VEVENT',
            "UID:{$uid}",
            "DTSTAMP:{$now}",
            "DTSTART:{$startStr}",
            "DTEND:{$endStr}",
            'SUMMARY:'.$this->escapeText($summary),
        ];

        if ($description) {
            $eventLines[] = 'DESCRIPTION:'.$this->escapeText($description);
        }

        if ($location) {
            $eventLines[] = 'LOCATION:'.$this->escapeText($location);
        }

        $eventLines[] = "URL;VALUE=URI:{$url}";
        $eventLines[] = 'STATUS:CONFIRMED';
        $eventLines[] = 'END:VEVENT';

        return $eventLines;
    }

    private function escapeText(?string $text): string
    {
        if ($text === null) {
            return '';
        }

        // RFC 5545 escaping
        $escaped = str_replace(
            ['\\', ';', ',', "\r\n", "\n", "\r"],
            ['\\\\', '\;', '\,', '\n', '\n', '\n'],
            $text
        );

        return $escaped;
    }
}
