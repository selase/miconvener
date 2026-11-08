<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\EventAttendeeImportRequest;
use App\Models\Event;
use App\Models\EventAttendeeImport;
use App\Services\Events\AttendeeImport;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

final class EventAttendeeImportController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $response = $next($request);
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        });
    }

    public function index(EventAttendeeImportRequest $request, string $subdomain, string $event): JsonResponse
    {
        return response()->json(['imports' => EventAttendeeImport::query()->where('tenant_id', app(TenantContext::class)->getTenant()?->id)->where('event_id', $event)->where('user_id', $request->user()->id)->latest()->limit(10)->get(['id', 'status', 'created_at'])])->header('Cache-Control', 'private, no-store');
    }

    public function template(EventAttendeeImportRequest $request, string $subdomain, string $event): \Illuminate\Http\Response
    {
        return response(implode(',', AttendeeImport::HEADERS)."\r\n", 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="attendees.csv"', 'Cache-Control' => 'private, no-store']);
    }

    public function preview(EventAttendeeImportRequest $request, string $subdomain, string $event, AttendeeImport $service): JsonResponse
    {
        $eventModel = Event::query()->where('tenant_id', app(TenantContext::class)->getTenant()?->id)->findOrFail($event);
        $import = $service->preview($eventModel, $request->user(), $request->file('file'), $request->input('mapping', []), $request->input('ticket_mapping', []), $request->input('source_namespace', 'csv'));

        return $this->result($import, $request);
    }

    public function show(EventAttendeeImportRequest $request, string $subdomain, string $event, string $import): JsonResponse
    {
        return $this->result($this->find($request, $event, $import), $request);
    }

    public function confirm(EventAttendeeImportRequest $request, string $subdomain, string $event, string $import, AttendeeImport $service): JsonResponse
    {
        return $this->result($service->confirm($this->find($request, $event, $import), $request->validated('operation_key'), $request->validated('rows')), $request);
    }

    public function resume(EventAttendeeImportRequest $request, string $subdomain, string $event, string $import, AttendeeImport $service): JsonResponse
    {
        $batch = $this->find($request, $event, $import);
        $service->dispatch($batch);

        return $this->result($batch->fresh(), $request);
    }

    public function invite(EventAttendeeImportRequest $request, string $subdomain, string $event, string $import, \App\Services\Events\AttendeeImportInvitations $service): JsonResponse
    {
        $batch = $this->find($request, $event, $import);
        $service->select($batch, $request->validated('operation_key'), $request->validated('registrations'), $request->validated('revisions'));

        return $this->result($batch, $request);
    }

    public function retryInvitations(EventAttendeeImportRequest $request, string $subdomain, string $event, string $import, \App\Services\Events\AttendeeImportInvitations $service): JsonResponse
    {
        $batch = $this->find($request, $event, $import);
        $service->dispatchPending($batch->id);

        return $this->result($batch, $request);
    }

    public function export(EventAttendeeImportRequest $request, string $subdomain, string $event, string $import): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $batch = $this->find($request, $event, $import);

        return response()->streamDownload(function () use ($batch): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['Line', 'Status', 'Name', 'Email', 'Errors'], escape: '');
            $batch->rows()->chunk(100, function ($rows) use ($handle): void {
                foreach ($rows as $row) {
                    $data = $row->data;
                    $cells = [$row->line, $row->status, $data['full_name'] ?? '', $data['email'] ?? '', implode(' ', $row->errors ?? [])];
                    fputcsv($handle, array_map(\App\Support\CsvCell::safe(...), $cells), escape: '');
                }
            });
            fclose($handle);
        }, 'attendee-import-results.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function find(EventAttendeeImportRequest $request, string $event, string $import): EventAttendeeImport
    {
        return EventAttendeeImport::query()->where('tenant_id', app(TenantContext::class)->getTenant()?->id)->where('event_id', $event)->where('user_id', $request->user()->id)->findOrFail($import);
    }

    private function result(EventAttendeeImport $import, EventAttendeeImportRequest $request): JsonResponse
    {
        $finance = $request->user()->can('read finance');
        $registrations = \App\Models\EventRegistration::query()->where('tenant_id', $import->tenant_id)->where('event_id', $import->event_id)->where('attendee_import_id', $import->id)->get(['id', 'email', 'member_benefit_access_version'])->keyBy('id');
        $rows = $import->rows->map(function ($row) use ($finance, $registrations): array {
            $recipient = $registrations->get($row->registration_id);
            $data = $row->data;
            if (! $finance && $data !== null) {
                unset($data['external_amount'], $data['currency'], $data['external_reference']);
            }
            if ($data !== null) {
                unset($data['ticket_revision']);
            }

            return ['id' => $row->id, 'line' => $row->line, 'status' => $row->status, 'data' => $data, 'errors' => $row->errors, 'registration_id' => $row->registration_id, 'invitation_recipient' => $recipient?->email, 'invitation_revision' => $recipient?->memberBenefitRecipientToken()];
        });

        return response()->json(['id' => $import->id, 'status' => $import->status, 'expires_at' => $import->expires_at, 'message' => $import->message, 'rows' => $rows, 'counts' => $rows->countBy('status'), 'invitations' => \App\Models\EventAttendeeImportInvitation::query()->where('import_id', $import->id)->get(['status'])->countBy('status')])->header('Cache-Control', 'private, no-store');
    }
}
