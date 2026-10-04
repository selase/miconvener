<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventDoorScan;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
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

        $scans = EventDoorScan::query()
            ->where('event_id', $eventModel->id)
            ->whereIn('outcome', [EventDoorScan::OUTCOME_ADMITTED, EventDoorScan::OUTCOME_DUPLICATE])
            ->with(['registration:id,full_name,ticket_code', 'staffLink:id,name', 'user:id,first_name,last_name'])
            ->orderBy('scanned_at')
            ->get();

        // Day => registration id => that guest's admitting scans, in order.
        $byDay = [];
        foreach ($scans as $scan) {
            $byDay[$scan->event_day->toDateString()][$scan->registration_id][] = $scan;
        }
        ksort($byDay);

        $days = [];
        $usedTwice = [];
        foreach ($byDay as $day => $guests) {
            $days[] = ['day' => $day, 'number' => $eventModel->dayNumber($day), 'admitted' => count($guests)];

            foreach ($guests as $registrationId => $entries) {
                if (count($entries) < 2) {
                    continue;
                }

                $usedTwice[] = [
                    'registration_id' => $registrationId,
                    'name' => $entries[0]->registration?->full_name,
                    'ticket_code' => $entries[0]->registration?->ticket_code,
                    'day' => $day,
                    'number' => $eventModel->dayNumber($day),
                    'entries' => array_map(fn (EventDoorScan $scan): array => [
                        'at' => $scan->scanned_at->copy()->setTimezone($timezone)->format('H:i'),
                        'by' => $scan->doorLabel(),
                        'offline' => $scan->was_offline,
                    ], $entries),
                ];
            }
        }

        return response()->json(['days' => $days, 'used_twice' => $usedTwice]);
    }
}
