<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventForumThread;
use App\Models\EventPollResponse;
use App\Models\EventRegistration;
use App\Models\EventSession;
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

    /**
     * @var string[]
     */
    private const array DEFAULT_REGISTRATION_COLUMNS = ['name', 'email', 'status', 'ticket_type', 'ticket_code', 'checked_in_at'];

    public function index(string $subdomain, string $event): JsonResponse
    {
        $this->authorize('read event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        return response()->json([
            'counts' => [
                'registrations' => $eventModel->registrations()->confirmed()->count(),
                'checked_in' => $eventModel->registrations()->where('status', EventRegistration::STATUS_CHECKED_IN)->count(),
                'forum_threads' => $eventModel->forumThreads()->count(),
                'poll_responses' => EventPollResponse::whereIn('poll_id', $eventModel->polls()->pluck('id'))->count(),
            ],
            'available_columns' => collect(self::REGISTRATION_COLUMNS)->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])->values(),
            'default_columns' => self::DEFAULT_REGISTRATION_COLUMNS,
        ]);
    }

    public function exportRegistrations(Request $request, string $subdomain, string $event): StreamedResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $validated = $request->validate([
            'columns' => ['sometimes', 'array'],
            'columns.*' => [Rule::in(array_keys(self::REGISTRATION_COLUMNS))],
        ]);

        $columns = ! empty($validated['columns'])
            ? array_values(array_intersect(array_keys(self::REGISTRATION_COLUMNS), $validated['columns']))
            : self::DEFAULT_REGISTRATION_COLUMNS;

        $seatLabels = $eventModel->seatAssignments()->get(['registration_id', 'seat_label'])->keyBy('registration_id');

        $filename = "{$eventModel->slug}-registrations.csv";

        return response()->streamDownload(function () use ($eventModel, $columns, $seatLabels): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, array_map(fn (string $key): string => self::REGISTRATION_COLUMNS[$key], $columns));

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
     * Reflects who added a session to their day (and, for capacity-gated
     * workshops, actually signed up) — this app has no per-session
     * scan-in/out device, so that is the strongest attendance signal it has.
     */
    public function exportSessionAttendance(string $subdomain, string $event): StreamedResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $filename = "{$eventModel->slug}-session-attendance.csv";

        return response()->streamDownload(function () use ($eventModel): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['Session', 'Starts at', 'Location', 'Capacity', 'Attendee name', 'Attendee email', 'Ticket type']);

            $eventModel->sessions()->with(['registrations.ticketType:id,name'])->orderBy('starts_at')->get()
                ->each(function (EventSession $session) use ($handle): void {
                    foreach ($session->registrations as $registration) {
                        fputcsv($handle, [
                            $session->title,
                            $session->starts_at->toIso8601String(),
                            $session->location,
                            $session->capacity,
                            $registration->full_name,
                            $registration->email,
                            $registration->ticketType?->name,
                        ]);
                    }
                });

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
        return (string) match ($key) {
            'name' => $registration->full_name,
            'email' => $registration->email,
            'phone' => $registration->phone,
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
