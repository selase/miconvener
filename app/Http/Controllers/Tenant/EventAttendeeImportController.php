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

    private function find(EventAttendeeImportRequest $request, string $event, string $import): EventAttendeeImport
    {
        return EventAttendeeImport::query()->where('tenant_id', app(TenantContext::class)->getTenant()?->id)->where('event_id', $event)->where('user_id', $request->user()->id)->findOrFail($import);
    }

    private function result(EventAttendeeImport $import, EventAttendeeImportRequest $request): JsonResponse
    {
        $finance = $request->user()->can('read finance');
        $rows = $import->rows->map(function ($row) use ($finance): array {
            $data = $row->data;
            if (! $finance && $data !== null) {
                unset($data['external_amount'], $data['currency'], $data['external_reference']);
            }
            if ($data !== null) {
                unset($data['ticket_revision']);
            }

            return ['id' => $row->id, 'line' => $row->line, 'status' => $row->status, 'data' => $data, 'errors' => $row->errors, 'registration_id' => $row->registration_id];
        });

        return response()->json(['id' => $import->id, 'status' => $import->status, 'expires_at' => $import->expires_at, 'message' => $import->message, 'rows' => $rows, 'counts' => $rows->countBy('status')])->header('Cache-Control', 'private, no-store');
    }
}
