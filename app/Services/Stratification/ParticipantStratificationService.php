<?php

declare(strict_types=1);

namespace App\Services\Stratification;

use App\Models\Event;
use App\Models\EventDynamicFormSubmission;
use App\Models\EventParticipantGroup;
use App\Models\EventRegistration;
use App\Models\EventSessionAttendance;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ParticipantStratificationService
{
    /**
     * Evaluate whether a single registration matches the given criteria rules.
     *
     * @param  array<int, array<string, mixed>>  $criteria
     */
    public function matchesCriteria(EventRegistration $registration, array $criteria): bool
    {
        if (empty($criteria)) {
            return false;
        }

        foreach ($criteria as $rule) {
            $type = $rule['type'] ?? null;
            $operator = $rule['operator'] ?? 'equals';
            $value = $rule['value'] ?? null;

            $matched = match ($type) {
                'ticket_type' => $this->checkTicketType($registration, $operator, $value),
                'status' => $this->checkStatus($registration, $operator, $value),
                'cme_hours' => $this->checkCmeHours($registration, $operator, (float) $value),
                'session_attendance' => $this->checkSessionAttendance($registration, $rule),
                'form_answer' => $this->checkFormAnswer($registration, $rule),
                default => true,
            };

            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    /**
     * Recompute and sync dynamic membership for an event participant group.
     *
     * @return array{matched_count: int, manual_count: int, total_count: int}
     */
    public function syncGroupMembers(EventParticipantGroup $group): array
    {
        $event = $group->event;
        $criteria = $group->criteria ?? [];

        if ($group->type === EventParticipantGroup::TYPE_MANUAL || empty($criteria)) {
            $group->update(['member_count' => $group->members()->count()]);

            return [
                'matched_count' => 0,
                'manual_count' => $group->members()->where('is_manual', true)->count(),
                'total_count' => $group->members()->count(),
            ];
        }

        $allRegistrations = $event->registrations()
            ->with(['ticketType'])
            ->get();

        $matchingIds = [];
        foreach ($allRegistrations as $reg) {
            if ($this->matchesCriteria($reg, $criteria)) {
                $matchingIds[] = $reg->id;
            }
        }

        // Get existing manual members to preserve them
        $manualMemberRegIds = $group->members()
            ->where('is_manual', true)
            ->pluck('registration_id')
            ->all();

        // Delete dynamic members that no longer match
        $group->members()
            ->where('is_manual', false)
            ->whereNotIn('registration_id', $matchingIds)
            ->delete();

        // Add or update matching registrations
        $now = now();
        foreach ($matchingIds as $regId) {
            if (! in_array($regId, $manualMemberRegIds, true)) {
                $group->members()->firstOrCreate(
                    ['registration_id' => $regId],
                    [
                        'tenant_id' => $group->tenant_id,
                        'event_id' => $group->event_id,
                        'is_manual' => false,
                        'matched_at' => $now,
                    ]
                );
            }
        }

        $totalCount = $group->members()->count();
        $group->update(['member_count' => $totalCount]);

        return [
            'matched_count' => count($matchingIds),
            'manual_count' => count($manualMemberRegIds),
            'total_count' => $totalCount,
        ];
    }

    /**
     * Stream CSV export of group roster.
     */
    public function exportGroupCsv(EventParticipantGroup $group): StreamedResponse
    {
        $members = $group->members()
            ->with(['registration.ticketType'])
            ->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="group-'.($group->slug ?? 'members').'-'.date('Ymd-His').'.csv"',
        ];

        return response()->stream(function () use ($members): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Full Name',
                'Email',
                'Ticket Type',
                'Registration Status',
                'Ticket Code',
                'Group Match Type',
                'Matched / Added At',
            ]);

            foreach ($members as $member) {
                $reg = $member->registration;
                if (! $reg) {
                    continue;
                }

                fputcsv($handle, [
                    $reg->full_name,
                    $reg->email,
                    $reg->ticketType?->name ?? 'Standard',
                    $reg->status,
                    $reg->ticket_code,
                    $member->is_manual ? 'Manual Pin' : 'Dynamic Rule Match',
                    $member->matched_at ? $member->matched_at->format('Y-m-d H:i:s') : '',
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }

    private function checkTicketType(EventRegistration $reg, string $operator, mixed $value): bool
    {
        $ticketTypeId = $reg->ticket_type_id;

        return match ($operator) {
            'is', 'equals' => $ticketTypeId === $value,
            'is_not', 'not_equals' => $ticketTypeId !== $value,
            'in' => is_array($value) && in_array($ticketTypeId, $value, true),
            default => true,
        };
    }

    private function checkStatus(EventRegistration $reg, string $operator, mixed $value): bool
    {
        $status = $reg->status;

        return match ($operator) {
            'is', 'equals' => $status === $value,
            'is_not', 'not_equals' => $status !== $value,
            'in' => is_array($value) && in_array($status, $value, true),
            default => true,
        };
    }

    private function checkCmeHours(EventRegistration $reg, string $operator, float $value): bool
    {
        $hours = (float) EventSessionAttendance::where('registration_id', $reg->id)->sum('cme_hours');

        return match ($operator) {
            'gte', '>=' => $hours >= $value,
            'lte', '<=' => $hours <= $value,
            'gt', '>' => $hours > $value,
            'lt', '<' => $hours < $value,
            default => $hours >= $value,
        };
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function checkSessionAttendance(EventRegistration $reg, array $rule): bool
    {
        $sessionId = $rule['session_id'] ?? null;
        $operator = $rule['operator'] ?? 'attended';
        $value = $rule['value'] ?? null;

        $query = EventSessionAttendance::where('registration_id', $reg->id);
        if ($sessionId && $sessionId !== 'any') {
            $query->where('session_id', $sessionId);
        }

        return match ($operator) {
            'attended' => $query->exists(),
            'not_attended' => ! $query->exists(),
            'min_minutes' => $query->sum('dwell_minutes') >= (int) $value,
            default => $query->exists(),
        };
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function checkFormAnswer(EventRegistration $reg, array $rule): bool
    {
        $formId = $rule['form_id'] ?? null;
        $fieldKey = $rule['field_key'] ?? null;
        $operator = $rule['operator'] ?? 'equals';
        $expected = $rule['value'] ?? null;

        if (! $fieldKey) {
            return true;
        }

        // Check dynamic form submissions for this registration or email
        $query = EventDynamicFormSubmission::where(function ($q) use ($reg): void {
            $q->where('registration_id', $reg->id)
                ->orWhere('respondent_email', $reg->email);
        });

        if ($formId) {
            $query->where('form_id', $formId);
        }

        $submissions = $query->get();
        if ($submissions->isEmpty()) {
            return $operator === 'is_not_filled';
        }

        foreach ($submissions as $sub) {
            $answers = $sub->answers ?? [];
            $actual = $answers[$fieldKey] ?? null;

            $matched = match ($operator) {
                'equals' => is_string($actual) && is_string($expected)
                    ? strcasecmp(mb_trim($actual), mb_trim($expected)) === 0
                    : $actual === $expected,
                'not_equals' => $actual !== $expected,
                'contains' => is_string($actual) && is_string($expected)
                    ? str_contains(mb_strtolower($actual), mb_strtolower($expected))
                    : false,
                'is_filled' => ! empty($actual),
                'is_not_filled' => empty($actual),
                default => false,
            };

            if ($matched) {
                return true;
            }
        }

        return false;
    }
}
