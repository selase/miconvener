<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventSession;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class RecurrenceService
{
    public const array PATTERNS = [
        'weekly',
        'biweekly',
        'monthly',
        'custom_days',
        'daily',
    ];

    public const array DAYS_OF_WEEK = [
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday',
    ];

    /**
     * Map day names to ISO day of week (1 = Monday, 7 = Sunday).
     */
    private const array DAY_MAP = [
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
        'saturday' => 6,
        'sunday' => 7,
    ];

    /**
     * Generate concrete EventSession occurrences for a recurring event up to the specified horizon.
     *
     * @return Collection<int, EventSession>
     */
    public function generateOccurrences(Event $event, ?CarbonInterface $until = null): Collection
    {
        if (! $event->isRecurring()) {
            return collect();
        }

        $tz = $this->safeTimezone($event->timezone);
        $now = CarbonImmutable::now($tz);
        $seriesStart = CarbonImmutable::parse($event->starts_at, $tz)->startOfDay();
        $from = $now->greaterThan($seriesStart) ? $now->startOfDay() : $seriesStart;

        $horizonWeeks = max(1, (int) ($event->recurrence_auto_generate_weeks ?: 4));
        $horizonDate = $from->addWeeks($horizonWeeks)->endOfDay();

        if ($until) {
            $horizonDate = CarbonImmutable::instance($until)->setTimezone($tz)->endOfDay();
        }

        if ($event->recurrence_until) {
            $seriesUntil = CarbonImmutable::parse($event->recurrence_until, $tz)->endOfDay();
            if ($seriesUntil->lessThan($horizonDate)) {
                $horizonDate = $seriesUntil;
            }
        }

        $targetDates = $this->calculateTargetDates($event, $from, $horizonDate);
        $createdSessions = collect();

        $startTimeStr = $event->recurrence_time_start ?: '09:00';
        $endTimeStr = $event->recurrence_time_end ?: '11:00';

        $startParts = explode(':', (string) $startTimeStr);
        $startHour = (int) $startParts[0];
        $startMinute = (int) ($startParts[1] ?? 0);

        $endParts = explode(':', (string) $endTimeStr);
        $endHour = (int) $endParts[0];
        $endMinute = (int) ($endParts[1] ?? 0);

        foreach ($targetDates as $date) {
            $dateStr = $date->toDateString();

            [$title, $type] = $this->resolveOccurrenceTitleAndType($event, $date);

            $startsAt = $date->setTime($startHour, $startMinute, 0);
            $endsAt = $date->setTime($endHour, $endMinute, 0);

            // Handle overnight or past-midnight sessions if end is earlier than start
            if ($endsAt->lessThanOrEqualTo($startsAt)) {
                $endsAt = $endsAt->addDay();
            }

            // Atomic creation / retrieval to prevent concurrent race condition duplicates
            $session = EventSession::firstOrCreate(
                [
                    'event_id' => $event->id,
                    'occurrence_date' => $dateStr,
                ],
                [
                    'tenant_id' => $event->tenant_id,
                    'title' => $title,
                    'description' => "Regular gathering of {$event->name}",
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'location' => $event->address ?: 'Main Sanctuary / Hall',
                    'type' => $type,
                    'capacity' => $event->capacity,
                    'is_occurrence' => true,
                    'occurrence_status' => EventSession::STATUS_SCHEDULED,
                ]
            );

            if (! $session->is_occurrence) {
                $session->update(['is_occurrence' => true]);
            }

            $createdSessions->push($session);
        }

        return $createdSessions;
    }

    /**
     * Preview projected future occurrence dates for modal/preview displays.
     *
     * @return array<int, array{date: string, day_name: string, title: string, type: string, start_time: string, end_time: string}>
     */
    public function previewOccurrences(Event $event, int $count = 8): array
    {
        if (! $event->isRecurring()) {
            return [];
        }

        $tz = $this->safeTimezone($event->timezone);
        $now = CarbonImmutable::now($tz);
        $seriesStart = CarbonImmutable::parse($event->starts_at, $tz)->startOfDay();
        $from = $now->greaterThan($seriesStart) ? $now->startOfDay() : $seriesStart;

        $horizonDate = $from->addMonths(3);

        if ($event->recurrence_until) {
            $seriesUntil = CarbonImmutable::parse($event->recurrence_until, $tz)->endOfDay();
            if ($seriesUntil->lessThan($horizonDate)) {
                $horizonDate = $seriesUntil;
            }
        }

        $dates = $this->calculateTargetDates($event, $from, $horizonDate);
        $preview = [];

        foreach (array_slice($dates, 0, $count) as $date) {
            [$title, $type] = $this->resolveOccurrenceTitleAndType($event, $date);
            $preview[] = [
                'date' => $date->toDateString(),
                'day_name' => $date->format('l'),
                'title' => $title,
                'type' => $type,
                'start_time' => $event->recurrence_time_start ?: '09:00',
                'end_time' => $event->recurrence_time_end ?: '11:00',
            ];
        }

        return $preview;
    }

    /**
     * Provide a clear, natural-language description of the recurrence schedule.
     */
    public function describeSchedule(Event $event): string
    {
        if (! $event->isRecurring()) {
            return 'One-time event';
        }

        $days = array_map('ucfirst', $event->recurrenceDays());
        $pluralDays = array_map(fn (string $d): string => str_ends_with(mb_strtolower($d), 's') ? $d : $d.'s', $days);

        $daysList = match (count($pluralDays)) {
            0 => 'scheduled days',
            1 => $pluralDays[0],
            2 => "{$pluralDays[0]} and {$pluralDays[1]}",
            default => implode(', ', array_slice($pluralDays, 0, -1)).' & '.end($pluralDays),
        };

        $timeRange = '';
        if ($event->recurrence_time_start && $event->recurrence_time_end) {
            $timeRange = " at {$event->recurrence_time_start} - {$event->recurrence_time_end}";
        } elseif ($event->recurrence_time_start) {
            $timeRange = " at {$event->recurrence_time_start}";
        }

        $pattern = match ($event->recurrence_pattern) {
            'biweekly' => 'Bi-weekly on ',
            'monthly' => 'Monthly on ',
            'daily' => 'Daily',
            default => 'Weekly on ',
        };

        $prefix = $event->recurrence_pattern === 'daily' ? 'Daily' : $pattern.$daysList;

        if ($event->recurrence_until) {
            $prefix .= " until {$event->recurrence_until->format('M j, Y')}";
        }

        return $prefix.$timeRange;
    }

    /**
     * Calculate dates matching recurrence rules between start and horizon date.
     *
     * @return array<int, CarbonImmutable>
     */
    private function calculateTargetDates(Event $event, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tz = $this->safeTimezone($event->timezone);
        $anchorDate = CarbonImmutable::parse($event->starts_at, $tz)->startOfDay();

        $days = $event->recurrenceDays();
        if (empty($days) && $event->recurrence_pattern !== 'daily') {
            $days = [mb_strtolower($anchorDate->format('l'))];
        }

        $isoDays = array_map(fn ($d) => self::DAY_MAP[mb_strtolower((string) $d)] ?? null, $days);
        $isoDays = array_filter($isoDays);

        $results = [];
        $cursor = $from->startOfDay();
        $interval = max(1, (int) ($event->recurrence_interval ?: 1));
        $pattern = $event->recurrence_pattern ?: 'weekly';

        while ($cursor->lessThanOrEqualTo($to)) {
            $dayOfWeekIso = $cursor->dayOfWeekIso;
            $matchesDay = in_array($dayOfWeekIso, $isoDays, true);

            if ($pattern === 'daily') {
                $diffDays = $anchorDate->diffInDays($cursor);
                if ($diffDays % $interval === 0) {
                    $results[] = $cursor;
                }
            } elseif ($pattern === 'biweekly' || ($pattern === 'weekly' && $interval > 1)) {
                $effectiveInterval = $pattern === 'biweekly' ? 2 : $interval;
                $diffWeeks = (int) floor($anchorDate->diffInDays($cursor->startOfWeek()) / 7);
                if ($matchesDay && ($diffWeeks % $effectiveInterval === 0)) {
                    $results[] = $cursor;
                }
            } elseif ($pattern === 'monthly') {
                if ($matchesDay && $cursor->weekOfMonth === $anchorDate->weekOfMonth) {
                    $results[] = $cursor;
                }
            } elseif ($matchesDay) {
                $results[] = $cursor;
            }

            $cursor = $cursor->addDay();
        }

        return $results;
    }

    /**
     * Derive a contextual default title and session type based on day and event name.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveOccurrenceTitleAndType(Event $event, CarbonImmutable $date): array
    {
        $dayLower = mb_strtolower($date->format('l'));
        $nameLower = mb_strtolower($event->name);

        if (str_contains($nameLower, 'lecture') || str_contains($nameLower, 'course') || str_contains($nameLower, 'class')) {
            $weekNum = ceil($date->diffInWeeks($event->starts_at) + 1);

            return ["Lecture: Week {$weekNum}", EventSession::TYPE_LECTURE];
        }

        return match ($dayLower) {
            'sunday' => ['Sunday Worship Service', EventSession::TYPE_SERVICE],
            'wednesday' => ['Midweek Bible Study', EventSession::TYPE_BIBLE_STUDY],
            'friday' => ['Prayer & Intercession Gathering', EventSession::TYPE_PRAYER],
            default => ["{$event->name} Gathering", EventSession::TYPE_SERVICE],
        };
    }

    /**
     * Ensure a valid timezone string is returned without throwing exception.
     */
    private function safeTimezone(?string $tz): string
    {
        if ($tz && @timezone_open($tz) !== false) {
            return $tz;
        }

        return 'UTC';
    }
}
