<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Exceptions\PaymentFailedException;
use App\Http\Controllers\Controller;
use App\Mail\Events\EventRegistrationConfirmed;
use App\Mail\Events\EventRegistrationPaymentInvite;
use App\Mail\Events\EventRegistrationRejected;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Payment\PaystackGateway;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class EventRegistrationController extends Controller
{
    public function approve(string $subdomain, string $event, string $registration): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);
        $registrationModel = $eventModel->registrations()->where('id', $registration)->firstOrFail();

        if ($registrationModel->status !== EventRegistration::STATUS_PENDING_APPROVAL) {
            return response()->json(['message' => 'This registration is not awaiting approval.'], 422);
        }

        $this->confirmOrInviteToPay($registrationModel, 'approved');

        return response()->json(['message' => 'Registration approved.']);
    }

    public function reject(Request $request, string $subdomain, string $event, string $registration): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);
        $registrationModel = $eventModel->registrations()->where('id', $registration)->firstOrFail();

        if ($registrationModel->status !== EventRegistration::STATUS_PENDING_APPROVAL) {
            return response()->json(['message' => 'This registration is not awaiting approval.'], 422);
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $registrationModel->update([
            'status' => EventRegistration::STATUS_REJECTED,
            'approval_note' => $validated['note'] ?? null,
        ]);

        Mail::to($registrationModel->email)->queue(new EventRegistrationRejected($registrationModel));

        return response()->json(['message' => 'Registration rejected.']);
    }

    public function cancel(string $subdomain, string $event, string $registration): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);
        $registrationModel = $eventModel->registrations()->where('id', $registration)->firstOrFail();

        $cancellable = [EventRegistration::STATUS_CONFIRMED, EventRegistration::STATUS_CHECKED_IN, EventRegistration::STATUS_WAITLISTED];
        if (! in_array($registrationModel->status, $cancellable, true)) {
            return response()->json(['message' => 'This registration cannot be cancelled.'], 422);
        }

        $wasPaid = $registrationModel->status !== EventRegistration::STATUS_WAITLISTED
            && $registrationModel->amount > 0
            && $registrationModel->payment_reference !== null;

        if ($wasPaid) {
            $refundFailure = $this->refundPaidRegistration($tenant, $registrationModel);
            if ($refundFailure !== null) {
                return $refundFailure;
            }
        }

        $freesUpASpot = $registrationModel->status !== EventRegistration::STATUS_WAITLISTED;
        $ticketTypeId = $registrationModel->ticket_type_id;

        $registrationModel->update(['status' => EventRegistration::STATUS_CANCELLED]);

        if ($freesUpASpot) {
            $this->promoteFromWaitlist($eventModel, $ticketTypeId);
        }

        return response()->json(['message' => 'Registration cancelled.']);
    }

    /**
     * Attempts the automatic Paystack refund for a paid registration being
     * cancelled. Returns null on success (caller proceeds to cancel); returns
     * a JsonResponse to short-circuit cancel() when the refund cannot be
     * issued, so a cancellation is never silently completed without a refund.
     */
    private function refundPaidRegistration(Tenant $tenant, EventRegistration $registrationModel): ?JsonResponse
    {
        $gateway = TenantPaymentGateway::where('tenant_id', $tenant->id)
            ->where('provider', 'paystack')
            ->where('is_active', true)
            ->first();

        if (! $gateway) {
            return response()->json(['message' => 'Cannot cancel: no active payment gateway is configured to process the refund.'], 422);
        }

        try {
            (new PaystackGateway(['secret_key' => $gateway->api_key_encrypted]))->refund($registrationModel->payment_reference);
        } catch (PaymentFailedException $e) {
            Log::error('Automatic refund on registration cancellation failed', [
                'tenant' => $tenant->id,
                'registration' => $registrationModel->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Cancellation aborted: the refund could not be processed. '.$e->getMessage()], 502);
        }

        return null;
    }

    private function confirmOrInviteToPay(EventRegistration $registration, string $reason): void
    {
        if ($registration->amount === 0) {
            $registration->update(['status' => EventRegistration::STATUS_CONFIRMED, 'waitlist_position' => null]);
            $registration->issueTicket();
            $registration->save();
            Mail::to($registration->email)->queue(new EventRegistrationConfirmed($registration));

            return;
        }

        $registration->update(['status' => EventRegistration::STATUS_PENDING_PAYMENT, 'waitlist_position' => null]);
        Mail::to($registration->email)->queue(new EventRegistrationPaymentInvite($registration, $reason));
    }

    private function promoteFromWaitlist(Event $event, ?string $ticketTypeId): void
    {
        $waitlistQuery = fn () => $ticketTypeId
            ? EventRegistration::where('ticket_type_id', $ticketTypeId)
            : EventRegistration::where('event_id', $event->id)->whereNull('ticket_type_id');

        $next = $waitlistQuery()->waitlisted()->orderBy('waitlist_position')->first();
        if (! $next) {
            return;
        }

        $promotedPosition = $next->waitlist_position;
        $this->confirmOrInviteToPay($next, 'promoted');

        // Close the gap so remaining waitlisted positions stay contiguous.
        $waitlistQuery()->waitlisted()->where('waitlist_position', '>', $promotedPosition)->decrement('waitlist_position');
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
