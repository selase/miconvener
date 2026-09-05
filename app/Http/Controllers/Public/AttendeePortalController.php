<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Mail\Events\EventRegistrationConfirmed;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class AttendeePortalController extends Controller
{
    public function addToAgenda(string $subdomain, string $event, string $registration, string $session): JsonResponse
    {
        $tenant = $this->getTenant();
        $registrationModel = $this->findRegistration($tenant->id, $event, $registration);

        $sessionModel = $registrationModel->event->sessions()->where('id', $session)->firstOrFail();

        $alreadySignedUp = $registrationModel->sessions()->where('session_id', $sessionModel->id)->exists();
        if (! $alreadySignedUp && $sessionModel->isFull()) {
            return response()->json(['message' => 'This session is full.'], 422);
        }

        $registrationModel->sessions()->syncWithoutDetaching([
            $sessionModel->id => ['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id],
        ]);

        return response()->json(['message' => 'Added to your day.']);
    }

    public function removeFromAgenda(string $subdomain, string $event, string $registration, string $session): JsonResponse
    {
        $tenant = $this->getTenant();
        $registrationModel = $this->findRegistration($tenant->id, $event, $registration);

        $registrationModel->sessions()->detach($session);

        return response()->json(['message' => 'Removed from your day.']);
    }

    public function transfer(Request $request, string $subdomain, string $event, string $registration): JsonResponse
    {
        $tenant = $this->getTenant();
        $registrationModel = $this->findRegistration($tenant->id, $event, $registration);

        if ($registrationModel->status === EventRegistration::STATUS_CHECKED_IN) {
            return response()->json(['message' => 'This ticket has already been checked in and can no longer be transferred.'], 422);
        }

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        $registrationModel->update($validated);

        if ($registrationModel->isConfirmed()) {
            Mail::to($registrationModel->email)->queue(new EventRegistrationConfirmed($registrationModel));
        }

        return response()->json(['message' => 'Ticket transferred.']);
    }

    private function findRegistration(string $tenantId, string $eventSlug, string $registrationId): EventRegistration
    {
        $eventModel = Event::where('tenant_id', $tenantId)->where('slug', $eventSlug)->published()->firstOrFail();

        return EventRegistration::where('tenant_id', $tenantId)
            ->where('event_id', $eventModel->id)
            ->where('id', $registrationId)
            ->firstOrFail();
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
