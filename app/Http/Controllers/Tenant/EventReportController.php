<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventForumThread;
use App\Models\EventPollResponse;
use App\Models\EventRegistration;
use App\Services\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

final class EventReportController extends Controller
{
    /**
     * @var array<string, string>
     */
    private const array REGISTRATION_COLUMNS = [
        'name' => 'Name',
        'email' => 'Email',
        'phone' => 'Phone',
        'status' => 'Status',
        'ticket_type' => 'Ticket type',
        'ticket_code' => 'Ticket code',
        'seat' => 'Seat',
        'amount' => 'Amount',
        'platform_fee' => 'Platform fee',
        'currency' => 'Currency',
        'checked_in_at' => 'Checked in at',
        'created_at' => 'Registered at',
    ];

    private const array OPTIONAL_COLUMNS = [
        'title' => 'Title',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'dietary_requirements' => 'Dietary requirements',
        'accessibility_needs' => 'Accessibility needs',
    ];

    /**
     * @var string[]
     */
    private const array DEFAULT_REGISTRATION_COLUMNS = ['name', 'email', 'status', 'ticket_type', 'ticket_code', 'checked_in_at'];

    public function index(string $subdomain, string $event): JsonResponse
    {
        $this->authorize('read event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $formFields = $eventModel->formFields()->ordered()->get();
        $customColumns = $formFields->map(fn ($f): array => [
            'key' => 'form_'.$f->field_key,
            'label' => $f->label,
        ]);

        $availableColumns = collect(self::REGISTRATION_COLUMNS)
            ->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])
            ->values()
            ->concat($customColumns);

        return response()->json([
            'counts' => [
                'registrations' => $eventModel->registrations()->confirmed()->count(),
                'checked_in' => $eventModel->registrations()->where('status', EventRegistration::STATUS_CHECKED_IN)->count(),
                'forum_threads' => $eventModel->forumThreads()->count(),
                'poll_responses' => EventPollResponse::whereIn('poll_id', $eventModel->polls()->pluck('id'))->count(),
            ],
            'available_columns' => $availableColumns,
            'default_columns' => self::DEFAULT_REGISTRATION_COLUMNS,
        ]);
    }

    public function exportRegistrations(Request $request, string $subdomain, string $event): StreamedResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $formFields = $eventModel->formFields()->ordered()->get();
        $customKeyToLabel = $formFields->mapWithKeys(fn ($f): array => ['form_'.$f->field_key => $f->label])->all();
        $allPossibleColumns = array_merge(self::REGISTRATION_COLUMNS, self::OPTIONAL_COLUMNS, $customKeyToLabel);

        $validated = $request->validate([
            'columns' => ['sometimes', 'array'],
            'columns.*' => [Rule::in(array_keys($allPossibleColumns))],
        ]);

        $columns = ! empty($validated['columns'])
            ? array_values(array_intersect(array_keys($allPossibleColumns), $validated['columns']))
            : array_merge(self::DEFAULT_REGISTRATION_COLUMNS, array_keys($customKeyToLabel));

        $seatLabels = $eventModel->seatAssignments()->get(['registration_id', 'seat_label'])->keyBy('registration_id');

        $filename = "{$eventModel->slug}-registrations.csv";

        return response()->streamDownload(function () use ($eventModel, $columns, $allPossibleColumns, $seatLabels): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, array_map(fn (string $key): string => $allPossibleColumns[$key] ?? $key, $columns));

            $eventModel->registrations()->with('ticketType:id,name')->orderBy('created_at')->chunk(200, function ($registrations) use ($handle, $columns, $seatLabels): void {
                foreach ($registrations as $registration) {
                    fputcsv($handle, array_map(
                        fn (string $key): string => $this->resolveRegistrationColumn($key, $registration, $seatLabels->get($registration->id)?->seat_label),
                        $columns,
                    ));
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function exportCheckins(string $subdomain, string $event): StreamedResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $filename = "{$eventModel->slug}-checkin-log.csv";

        return response()->streamDownload(function () use ($eventModel): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['Name', 'Ticket code', 'Checked in at', 'Checked in by']);

            $eventModel->registrations()
                ->where('status', EventRegistration::STATUS_CHECKED_IN)
                ->with('checkedInBy:id,first_name,last_name')
                ->orderBy('checked_in_at')
                ->chunk(200, function ($registrations) use ($handle): void {
                    foreach ($registrations as $registration) {
                        fputcsv($handle, [
                            $registration->full_name,
                            $registration->ticket_code,
                            $registration->checked_in_at?->toIso8601String(),
                            $registration->checkedInBy ? mb_trim($registration->checkedInBy->first_name.' '.$registration->checkedInBy->last_name) : null,
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function exportForum(string $subdomain, string $event): StreamedResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $filename = "{$eventModel->slug}-forum-activity.csv";

        return response()->streamDownload(function () use ($eventModel): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['Title', 'Author', 'Pinned', 'Answered', 'Replies', 'Posted at']);

            $eventModel->forumThreads()->withCount('replies')->get()->each(function (EventForumThread $thread) use ($handle): void {
                fputcsv($handle, [
                    $thread->title,
                    $thread->author_name,
                    $thread->is_pinned ? 'Yes' : 'No',
                    $thread->is_answered ? 'Yes' : 'No',
                    $thread->replies_count,
                    $thread->created_at->toIso8601String(),
                ]);
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function exportPolls(string $subdomain, string $event): StreamedResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $filename = "{$eventModel->slug}-poll-results.csv";

        return response()->streamDownload(function () use ($eventModel): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['Poll', 'Type', 'Answer', 'Submitted at']);

            $eventModel->polls()->with(['responses.option'])->get()->each(function ($poll) use ($handle): void {
                foreach ($poll->responses as $response) {
                    fputcsv($handle, [
                        $poll->question,
                        $poll->type,
                        $response->option?->label ?? $response->response_text,
                        $response->created_at->toIso8601String(),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Deduplicated by email across every event this tenant has run — not
     * just this one — so a repeat attendee's full history shows up.
     */
    public function exportAttendeeDirectory(string $subdomain, string $event): StreamedResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $filename = "{$eventModel->slug}-attendee-directory.csv";

        return response()->streamDownload(function () use ($tenant): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['Name', 'Email', 'Phone', 'Events registered for', 'First registered', 'Last registered']);

            EventRegistration::where('tenant_id', $tenant->id)
                ->selectRaw('email, MIN(full_name) as full_name, MIN(phone) as phone, COUNT(DISTINCT event_id) as events_count, MIN(created_at) as first_registered_at, MAX(created_at) as last_registered_at')
                ->groupBy('email')
                ->orderBy('email')
                ->cursor()
                ->each(function ($row) use ($handle): void {
                    fputcsv($handle, [
                        $row->full_name,
                        $row->email,
                        $row->phone,
                        $row->events_count,
                        $row->first_registered_at,
                        $row->last_registered_at,
                    ]);
                });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Exports verified breakout session & workshop attendances with scan-in,
     * scan-out, dwell time, and CPD/CME contact hours calculation.
     */
    public function exportSessionAttendance(string $subdomain, string $event): StreamedResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $filename = "{$eventModel->slug}-session-attendance.csv";

        return response()->streamDownload(function () use ($eventModel): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, [
                'Session Title',
                'Location / Room',
                'Track',
                'Session Start',
                'Session End',
                'Attendee Title',
                'Attendee Name',
                'Attendee Email',
                'Ticket Code',
                'Status',
                'Check-in Time',
                'Check-out Time',
                'Dwell Time (Mins)',
                'CPD Contact Hours',
            ]);

            $sessions = $eventModel->sessions()
                ->with([
                    'attendances.registration.ticketType:id,name',
                    'registrations.ticketType:id,name',
                ])
                ->orderBy('starts_at')
                ->get();

            foreach ($sessions as $session) {
                $scannedRegistrationIds = [];

                // 1. Scanned attendances (verified scan-in / scan-out)
                foreach ($session->attendances as $attendance) {
                    $registration = $attendance->registration;
                    if ($registration) {
                        $scannedRegistrationIds[] = $registration->id;
                    }

                    $dwellMinutes = $attendance->durationMinutes();
                    $contactHours = $attendance->contactHoursEarned();
                    $status = $attendance->isCurrentlyInRoom() ? 'In Room (Active)' : 'Completed';

                    fputcsv($handle, [
                        $session->title,
                        $session->location ?: 'Main Hall',
                        $session->track ?: 'General',
                        $session->starts_at?->toIso8601String(),
                        $session->ends_at?->toIso8601String(),
                        $registration?->title,
                        $registration?->full_name,
                        $registration?->email,
                        $registration?->ticket_code,
                        $status,
                        $attendance->checked_in_at?->toIso8601String(),
                        $attendance->checked_out_at?->toIso8601String(),
                        $dwellMinutes,
                        $contactHours,
                    ]);
                }

                // 2. Pre-registered attendees who haven't scanned in
                foreach ($session->registrations as $reg) {
                    if (in_array($reg->id, $scannedRegistrationIds, true)) {
                        continue;
                    }

                    fputcsv($handle, [
                        $session->title,
                        $session->location ?: 'Main Hall',
                        $session->track ?: 'General',
                        $session->starts_at?->toIso8601String(),
                        $session->ends_at?->toIso8601String(),
                        $reg->title,
                        $reg->full_name,
                        $reg->email,
                        $reg->ticket_code,
                        'Registered (Absent)',
                        '',
                        '',
                        0,
                        0.00,
                    ]);
                }
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Catering/access counts feed off free-text fields collected at
     * registration, grouped case-insensitively so "Vegetarian" and
     * "vegetarian" count together.
     */
    public function exportDietaryAccessibility(string $subdomain, string $event): StreamedResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $filename = "{$eventModel->slug}-dietary-accessibility.csv";

        return response()->streamDownload(function () use ($eventModel): void {
            $handle = fopen('php://output', 'wb');

            $registrations = $eventModel->registrations()->confirmed()->get(['dietary_requirements', 'accessibility_needs']);

            fputcsv($handle, ['Dietary requirement', 'Count']);
            $this->writeGroupedCounts($handle, $registrations->pluck('dietary_requirements'));

            fputcsv($handle, []);
            fputcsv($handle, ['Accessibility need', 'Count']);
            $this->writeGroupedCounts($handle, $registrations->pluck('accessibility_needs'));

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function exportAuditLog(string $subdomain, string $event): StreamedResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $filename = "{$eventModel->slug}-audit-log.csv";

        return response()->streamDownload(function () use ($eventModel): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['When', 'Action', 'Subject', 'Changed by', 'Changes']);

            $registrationIds = $eventModel->registrations()->pluck('id');

            Activity::query()
                ->where(function ($q) use ($eventModel, $registrationIds): void {
                    $q->where(fn ($q2) => $q2->where('subject_type', Event::class)->where('subject_id', $eventModel->id))
                        ->orWhere(fn ($q2) => $q2->where('subject_type', EventRegistration::class)->whereIn('subject_id', $registrationIds));
                })
                ->with('causer')
                ->orderByDesc('created_at')
                ->cursor()
                ->each(function (Activity $activity) use ($handle): void {
                    fputcsv($handle, [
                        $activity->created_at->toIso8601String(),
                        $activity->description,
                        class_basename((string) $activity->subject_type).' #'.$activity->subject_id,
                        $activity->causer?->displayName() ?? 'System',
                        json_encode($activity->properties),
                    ]);
                });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function exportCertificates(string $subdomain, string $event): BinaryFileResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $attendees = $eventModel->registrations()->where('status', EventRegistration::STATUS_CHECKED_IN)->orderBy('full_name')->get();

        if ($attendees->isEmpty()) {
            abort(404, 'No checked-in attendees to generate certificates for.');
        }

        $zipPath = Storage::disk('local')->path('certificates-'.Str::uuid().'.zip');
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);

        foreach ($attendees as $attendee) {
            $pdf = Pdf::loadView('pdf.certificate', ['event' => $eventModel, 'attendee' => $attendee])->setPaper('a4', 'landscape');
            $zip->addFromString(Str::slug($attendee->full_name).'-certificate.pdf', $pdf->output());
        }

        $zip->close();

        return response()->download($zipPath, "{$eventModel->slug}-certificates.zip")->deleteFileAfterSend();
    }

    public function exportAbstractBook(string $subdomain, string $event): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorize('read event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $pdf = app(\App\Services\Programme\AbstractBookService::class)->generatePdf($eventModel);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$eventModel->slug.'-abstract-book.pdf"',
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, string|null>  $values
     */
    private function writeGroupedCounts($handle, $values): void
    {
        $values
            ->map(fn (?string $v): string => $v && mb_trim($v) !== '' ? mb_trim($v) : 'Not specified')
            ->countBy(fn (string $v): string => mb_strtolower($v))
            ->sortDesc()
            ->each(function (int $count, string $key) use ($handle): void {
                fputcsv($handle, [$key, $count]);
            });
    }

    private function resolveRegistrationColumn(string $key, EventRegistration $registration, ?string $seatLabel): string
    {
        if (str_starts_with($key, 'form_')) {
            $fieldKey = mb_substr($key, 5);
            $answer = $registration->form_answers[$fieldKey] ?? null;
            if (is_array($answer)) {
                return implode(', ', array_map(fn ($item): string => (string) $item, $answer));
            }
            if (is_bool($answer)) {
                return $answer ? 'Yes' : 'No';
            }

            return (string) ($answer ?? '');
        }

        return (string) match ($key) {
            'title' => $registration->title,
            'first_name' => $registration->first_name,
            'last_name' => $registration->last_name,
            'name' => $registration->full_name,
            'email' => $registration->email,
            'phone' => $registration->phone,
            'dietary_requirements' => $registration->dietary_requirements,
            'accessibility_needs' => $registration->accessibility_needs,
            'status' => $registration->status,
            'ticket_type' => $registration->ticketType?->name,
            'ticket_code' => $registration->ticket_code,
            'seat' => $seatLabel,
            'amount' => $registration->amount,
            'platform_fee' => $registration->platform_fee_amount,
            'currency' => $registration->currency,
            'checked_in_at' => $registration->checked_in_at?->toIso8601String(),
            'created_at' => $registration->created_at->toIso8601String(),
            default => '',
        };
    }

    private function findEvent(string $tenantId, string $eventId): Event
    {
        return Event::where('tenant_id', $tenantId)->where('id', $eventId)->firstOrFail();
    }

    private function getTenant()
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(403, 'Tenant context not resolved.');
        }

        return $tenant;
    }
}
