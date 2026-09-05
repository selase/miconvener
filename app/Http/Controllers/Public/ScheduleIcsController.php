<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Services\Events\IcsGenerator;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Response;

final class ScheduleIcsController extends Controller
{
    public function programme(string $subdomain, string $event): Response
    {
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('slug', $event)->published()
            ->with('sessions')
            ->firstOrFail();

        $ics = IcsGenerator::forSessions($eventModel, $eventModel->sessions);

        return $this->download($ics, "{$eventModel->slug}-programme.ics");
    }

    public function agenda(string $subdomain, string $event, string $registration): Response
    {
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('slug', $event)->published()->firstOrFail();
        $registrationModel = EventRegistration::where('tenant_id', $tenant->id)
            ->where('event_id', $eventModel->id)
            ->where('id', $registration)
            ->firstOrFail();

        $ics = IcsGenerator::forSessions($eventModel, $registrationModel->sessions()->orderBy('starts_at')->get());

        return $this->download($ics, "{$eventModel->slug}-my-day.ics");
    }

    private function download(string $ics, string $filename): Response
    {
        return response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function getTenant()
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404);
        }

        return $tenant;
    }
}
