<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventDoorScan;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The door log as the console shows it: entries per day, and the tickets
 * admitted twice in one day (only possible while a door was offline).
 */
final class EventDoorScanController extends Controller
{
    public function index(string $subdomain, string $event): JsonResponse
    {
        abort_unless(Gate::any([...EventCheckInController::PERMISSIONS, 'read event']), 403);

        $tenant = app(TenantContext::class)->getTenant();
        abort_unless($tenant instanceof Tenant, 403, 'Tenant context not resolved.');

        $eventModel = Event::where('tenant_id', $tenant->id)->where('id', $event)->firstOrFail();
        $timezone = $eventModel->timezone ?: 'Africa/Accra';

        $byDay = EventDoorScan::query()
            ->where('event_id', $eventModel->id)
            ->whereIn('outcome', [EventDoorScan::OUTCOME_ADMITTED, EventDoorScan::OUTCOME_DUPLICATE])
            ->with(['registration:id,full_name,ticket_code', 'staffLink:id,name', 'user:id,first_name,last_name'])
            ->orderBy('scanned_at')
            ->get()
            ->groupBy(fn (EventDoorScan $scan): string => $scan->event_day->toDateString());

        return response()->json([
            'days' => $byDay->map(fn (Collection $scans, string $day): array => [
                'day' => $day,
                'number' => $eventModel->dayNumber($day),
                'admitted' => $scans->pluck('registration_id')->unique()->count(),
            ])->sortKeys()->values(),
            'used_twice' => $byDay->flatMap(fn (Collection $scans, string $day): Collection => $scans
                ->groupBy('registration_id')
                ->filter(fn (Collection $entries): bool => $entries->count() > 1)
                ->map(fn (Collection $entries): array => [
                    'registration_id' => $entries->first()->registration_id,
                    'name' => $entries->first()->registration?->full_name,
                    'ticket_code' => $entries->first()->registration?->ticket_code,
                    'day' => $day,
                    'number' => $eventModel->dayNumber($day),
                    'entries' => $entries->map(fn (EventDoorScan $scan): array => [
                        'at' => $scan->scanned_at->copy()->setTimezone($timezone)->format('H:i'),
                        'by' => $scan->doorLabel(),
                        'offline' => $scan->was_offline,
                    ])->values(),
                ])
                ->values())->values(),
        ]);
    }
}
