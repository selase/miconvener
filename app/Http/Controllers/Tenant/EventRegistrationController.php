<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Exceptions\PaymentFailedException;
use App\Http\Controllers\Controller;
use App\Mail\Events\EventRegistrationConfirmed;
use App\Mail\Events\EventRegistrationPaymentInvite;
use App\Mail\Events\EventRegistrationRejected;
use App\Models\Event;
use App\Models\EventLedgerEntry;
use App\Models\EventRegistration;
use App\Models\MerchantTransaction;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Payment\PaystackGateway;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class EventRegistrationController extends Controller
{
    /**
     * Paystack's refund error code when the transaction was already
     * reversed before this attempt (e.g. the organizer refunded it manually
     * on the Paystack dashboard). The customer already has their money
     * back, so it is not a cancellation failure.
     */
    private const string PAYSTACK_TRANSACTION_ALREADY_REVERSED = 'transaction_reversed';

    /**
     * Ledger sentinel used in place of a provider refund id when Paystack
     * reports the transaction was already reversed, since that response
     * carries no fresh refund id for us to record.
     */
    private const string REFUND_ALREADY_REVERSED_MARKER = 'already_reversed';

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

        return DB::transaction(function () use ($tenant, $eventModel, $registration): JsonResponse {
            // Lock the row for the whole check-refund-write sequence so a
            // concurrent cancel request on the same registration (double
            // click, two admin sessions) blocks here instead of racing past
            // the status check and issuing a second Paystack refund.
            $registrationModel = $eventModel->registrations()->where('id', $registration)->lockForUpdate()->firstOrFail();

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
        });
    }

    /**
     * Attempts the automatic Paystack refund for a paid registration being
     * cancelled. Returns null on success (caller proceeds to cancel); returns
     * a JsonResponse to short-circuit cancel() when the refund cannot be
     * issued, so a cancellation is never silently completed without a refund.
     */
    private function refundPaidRegistration(Tenant $tenant, EventRegistration $registrationModel): ?JsonResponse
    {
        $paystack = $tenant->isPlatformDefaultSettlement()
            ? $this->platformRefundGateway()
            : $this->tenantRefundGateway($tenant);

        if (! $paystack) {
            $message = $tenant->isPlatformDefaultSettlement()
                ? 'Cannot cancel: the platform has not configured its own settlement credentials to process the refund.'
                : 'Cannot cancel: no active payment gateway is configured to process the refund.';

            return response()->json(['message' => $message], 422);
        }

        try {
            $refundId = $paystack->refund($registrationModel->payment_reference);
        } catch (PaymentFailedException $e) {
            if ($e->providerCode !== self::PAYSTACK_TRANSACTION_ALREADY_REVERSED) {
                Log::error('Automatic refund on registration cancellation failed', [
                    'tenant' => $tenant->id,
                    'registration' => $registrationModel->id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json(['message' => 'Cancellation aborted: the refund could not be processed. '.$e->getMessage()], 502);
            }

            // The money is already back with the customer, which is exactly
            // what cancelling this registration wanted, so treat it as a
            // successful refund rather than blocking cancellation forever.
            Log::info('Automatic refund on registration cancellation found the transaction already reversed on Paystack; completing cancellation', [
                'tenant' => $tenant->id,
                'registration' => $registrationModel->id,
            ]);

            $refundId = self::REFUND_ALREADY_REVERSED_MARKER;
        }

        $this->recordRefundInLedger($tenant, $registrationModel, $refundId);

        return null;
    }

    private function tenantRefundGateway(Tenant $tenant): ?PaystackGateway
    {
        $gateway = TenantPaymentGateway::where('tenant_id', $tenant->id)
            ->where('provider', 'paystack')
            ->where('is_active', true)
            ->first();

        return $gateway ? new PaystackGateway(['secret_key' => $gateway->api_key_encrypted]) : null;
    }

    private function platformRefundGateway(): ?PaystackGateway
    {
        $secret = config('services.settlement.paystack.secret_key');

        return $secret ? new PaystackGateway(['secret_key' => $secret]) : null;
    }

    /**
     * Mirrors the refund into the merchant ledger so the Finance dashboard's
     * volumes stay correct and the already-refunded charge no longer offers a
     * live Refund button. A missing original transaction row is logged rather
     * than raised — the money has already moved, so the cancellation must not
     * be rolled back over a bookkeeping gap.
     */
    private function recordRefundInLedger(Tenant $tenant, EventRegistration $registrationModel, string $refundId): void
    {
        $transaction = MerchantTransaction::where('tenant_id', $tenant->id)
            ->where('provider_transaction_id', $registrationModel->payment_reference)
            ->first();

        $chargeEntry = EventLedgerEntry::where('tenant_id', $tenant->id)
            ->where('type', EventLedgerEntry::TYPE_CHARGE)
            ->where('provider_reference', $registrationModel->payment_reference)
            ->first();

        if (! $transaction && ! $chargeEntry) {
            Log::warning('Automatic refund succeeded but no matching transaction or ledger entry was found to reconcile', [
                'tenant' => $tenant->id,
                'registration' => $registrationModel->id,
                'payment_reference' => $registrationModel->payment_reference,
                'refund_id' => $refundId,
            ]);

            return;
        }

        if ($transaction) {
            MerchantTransaction::create([
                'tenant_id' => $tenant->id,
                'provider' => $transaction->provider,
                'provider_transaction_id' => $this->hasNoProviderRefundId($refundId) ? 'REF_'.$transaction->provider_transaction_id : $refundId,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'status' => 'succeeded',
                'type' => 'refund',
                'description' => 'Refund for '.$transaction->provider_transaction_id,
                'customer_email' => $transaction->customer_email,
                'meta' => ['refund_id' => $refundId],
            ]);

            $transaction->update(['status' => 'refunded']);
        }

        if ($chargeEntry) {
            EventLedgerEntry::create([
                'tenant_id' => $tenant->id,
                'event_id' => $chargeEntry->event_id,
                'type' => EventLedgerEntry::TYPE_REFUND,
                'registration_id' => $registrationModel->id,
                'gross_amount' => $chargeEntry->gross_amount,
                'gateway_fee_amount' => $chargeEntry->gateway_fee_amount,
                'commission_amount' => $chargeEntry->commission_amount,
                'net_amount' => -$chargeEntry->net_amount,
                'currency' => $chargeEntry->currency,
                'provider' => $chargeEntry->provider,
                'provider_reference' => $this->hasNoProviderRefundId($refundId) ? 'REF_'.$chargeEntry->provider_reference : $refundId,
            ]);
        }
    }

    /**
     * True when the refund id is a local sentinel rather than a real
     * provider-issued refund id, meaning ledger rows must fall back to a
     * derived reference instead of recording it verbatim.
     */
    private function hasNoProviderRefundId(string $refundId): bool
    {
        return in_array($refundId, ['pending', self::REFUND_ALREADY_REVERSED_MARKER], true);
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
