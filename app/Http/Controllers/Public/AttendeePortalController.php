<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Mail\Events\EventRegistrationConfirmed;
use App\Mail\Events\EventTicketTransferCode;
use App\Mail\Events\EventTicketTransferred;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventRegistrationTransfer;
use App\Services\Tenancy\FeatureMeteringService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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

    /**
     * Step one of a transfer: stage it and mail a code to the address the ticket
     * is currently issued to.
     *
     * A transfer overwrites the holder's name and email irreversibly, and until
     * now it needed nothing but the portal URL -- so anyone forwarded the
     * confirmation email could take the ticket silently. Holding the link is no
     * longer sufficient; the request has to be confirmed from the inbox.
     */
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

        // Supersede any code already in flight, so an abandoned attempt cannot
        // be completed later with a stale code.
        EventRegistrationTransfer::query()
            ->where('registration_id', $registrationModel->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = EventRegistrationTransfer::generateCode();

        $transfer = EventRegistrationTransfer::query()->create([
            'tenant_id' => $tenant->id,
            'registration_id' => $registrationModel->id,
            'to_full_name' => $validated['full_name'],
            'to_email' => $validated['email'],
            'to_phone' => $validated['phone'] ?? null,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(EventRegistrationTransfer::TTL_MINUTES),
        ]);

        $transfer->setRelation('registration', $registrationModel);
        $transfer->plainCode = $code;

        Mail::to($registrationModel->email)->queue(new EventTicketTransferCode($transfer));
        app(FeatureMeteringService::class)->recordUsage($tenant, 'email_credits');

        return response()->json([
            'message' => "We emailed a confirmation code to {$this->maskEmail($registrationModel->email)}. Enter it to complete the transfer.",
            'transfer_id' => $transfer->id,
            'expires_in_minutes' => EventRegistrationTransfer::TTL_MINUTES,
        ]);
    }

    /**
     * Step two: check the code and hand the ticket over.
     */
    public function confirmTransfer(Request $request, string $subdomain, string $event, string $registration): JsonResponse
    {
        $tenant = $this->getTenant();
        $registrationModel = $this->findRegistration($tenant->id, $event, $registration);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:12'],
        ]);

        $transfer = EventRegistrationTransfer::query()
            ->where('registration_id', $registrationModel->id)
            ->whereNull('consumed_at')
            ->latest()
            ->first();

        if (! $transfer || ! $transfer->isUsable()) {
            return response()->json(['message' => 'That code has expired. Start the transfer again.'], 422);
        }

        if (! $transfer->matches($validated['code'])) {
            $transfer->increment('attempts');

            $remaining = max(0, EventRegistrationTransfer::MAX_ATTEMPTS - $transfer->attempts);

            return response()->json([
                'message' => $remaining > 0
                    ? "That code is not right. {$remaining} attempt(s) left."
                    : 'Too many incorrect codes. Start the transfer again.',
            ], 422);
        }

        // Re-check at the moment of handover: the ticket may have been scanned
        // in while the code sat in an inbox.
        if ($registrationModel->status === EventRegistration::STATUS_CHECKED_IN) {
            return response()->json(['message' => 'This ticket has been checked in and can no longer be transferred.'], 422);
        }

        $previousName = $registrationModel->full_name;
        $previousEmail = $registrationModel->email;

        $registrationModel->update([
            'full_name' => $transfer->to_full_name,
            'email' => $transfer->to_email,
            'phone' => $transfer->to_phone,
        ]);

        $transfer->update(['consumed_at' => now()]);

        // The person losing the ticket hears about it, so a theft is visible
        // rather than discovered at the door.
        Mail::to($previousEmail)->queue(new EventTicketTransferred(
            $registrationModel,
            $previousName,
            $registrationModel->full_name,
            $registrationModel->email,
        ));
        app(FeatureMeteringService::class)->recordUsage($tenant, 'email_credits');

        if ($registrationModel->isConfirmed()) {
            Mail::to($registrationModel->email)->queue(new EventRegistrationConfirmed($registrationModel));
            app(FeatureMeteringService::class)->recordUsage($tenant, 'email_credits');
        }

        return response()->json(['message' => 'Ticket transferred. The new holder has been emailed.']);
    }

    /**
     * Enough of the address to recognise your own inbox, not enough to learn
     * someone else's.
     */
    private function maskEmail(string $email): string
    {
        [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');

        $visible = mb_substr($user, 0, 2);

        return $visible.str_repeat('•', max(1, mb_strlen($user) - 2)).'@'.$domain;
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
