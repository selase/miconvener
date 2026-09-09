<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Mail\Events\EventTicketLink;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Services\Tenancy\FeatureMeteringService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * A ticket lives behind a link in an email, so losing the email loses the
 * ticket. This sends it again, without introducing accounts.
 */
final class TicketRecoveryController extends Controller
{
    public function store(Request $request, string $subdomain, string $event): JsonResponse
    {
        $tenant = app(TenantContext::class)->getTenant();

        if (! $tenant) {
            abort(404);
        }

        $eventModel = Event::where('tenant_id', $tenant->id)
            ->where('slug', $event)
            ->published()
            ->firstOrFail();

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $registration = EventRegistration::query()
            ->where('tenant_id', $tenant->id)
            ->where('event_id', $eventModel->id)
            ->where('email', $validated['email'])
            ->whereNotIn('status', [EventRegistration::STATUS_CANCELLED, EventRegistration::STATUS_REJECTED])
            ->latest()
            ->first();

        if ($registration) {
            $registration->setRelation('event', $eventModel);
            $registration->setRelation('tenant', $tenant);

            Mail::to($registration->email)->queue(new EventTicketLink($registration));
            app(FeatureMeteringService::class)->recordUsage($tenant, 'email_credits');
        }

        // The same answer either way. Anyone can type an address here, so a
        // different response for "found" would turn this into a way to discover
        // who is attending -- which for a private event is the guest list.
        return response()->json([
            'message' => 'If that address is registered for this event, the ticket link is on its way to it.',
        ]);
    }
}
