<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Commerce;

use App\Http\Controllers\Controller;
use App\Mail\Events\EventRegistrationConfirmed;
use App\Models\EventRegistration;
use App\Models\MerchantTransaction;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class WebhookController extends Controller
{
    /**
     * How the platform's commission on this payment was met, from what
     * checkout recorded in the metadata the gateway returns: the cash taken as
     * a Paystack split or Stripe application fee, and the credit the platform
     * owed the organizer that checkout netted against it. The credit is drawn
     * down here, once the payment is real; if another sale spent it first, the
     * shortfall simply falls to commission still owed.
     *
     * Paystack reports what the platform subaccount actually received
     * ($reportedReceived); when it does, that is what is booked as received,
     * not what checkout asked for, so a short split shows up as money owed.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{received: int, credit: int}
     */
    public static function commissionSettlement(Tenant $tenant, string $currency, string $provider, array $metadata, int $commission, ?int $reportedReceived = null): array
    {
        $creditRequested = max(0, (int) ($metadata['commission_credit_applied'] ?? 0));

        if ($provider === 'stripe') {
            $received = (int) ($metadata['commission_collected'] ?? 0);
        } elseif (filled($metadata['commission_split'] ?? null)) {
            $expected = max(0, $commission - $creditRequested);
            $received = $reportedReceived !== null ? min(max(0, $reportedReceived), $commission) : $expected;

            if ($reportedReceived !== null && $reportedReceived !== $expected) {
                Log::warning('The commission subaccount received a different amount than the split asked for', [
                    'tenant' => $tenant->id,
                    'expected' => $expected,
                    'received' => $reportedReceived,
                ]);
            }
        } else {
            $received = 0;
        }

        $credit = $creditRequested > 0
            ? app(\App\Services\Finance\CommissionCredit::class)->consume($tenant, $currency, $creditRequested)
            : 0;

        return ['received' => $received, 'credit' => $credit];
    }

    /**
     * Handle Stripe Connect events from tenants' connected accounts. One
     * endpoint serves every tenant: the `account` on each event says whose
     * account it came from.
     */
    public function handleStripeConnect(Request $request): JsonResponse
    {
        try {
            $event = \Stripe\Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                (string) config('services.stripe.connect.webhook_secret'),
            );
        } catch (Throwable $e) {
            Log::warning('Stripe Connect webhook verification failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $accountId = $event->account ?? null;
        $gateway = $accountId
            ? TenantPaymentGateway::where('provider', 'stripe')->where('provider_account_id', $accountId)->first()
            : null;

        if (! $gateway) {
            // Not one of ours (or already disconnected): acknowledge so Stripe stops retrying.
            return response()->json(['status' => 'ignored']);
        }

        $tenant = Tenant::find($gateway->tenant_id);

        try {
            switch ($event->type) {
                case 'checkout.session.completed':
                case 'checkout.session.async_payment_succeeded':
                    $session = $event->data->object;

                    if (($session->payment_status ?? null) === 'paid' && $tenant) {
                        $this->confirmStripeSession($tenant, $gateway, $session);
                    }
                    break;

                case 'account.application.deauthorized':
                    $gateway->delete();
                    break;
            }
        } catch (Throwable $e) {
            Log::error('Stripe Connect webhook processing failed', [
                'tenant' => $gateway->tenant_id,
                'event' => $event->type,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Handle Paystack Webhooks for Merchant accounts.
     */
    public function handlePaystack(Request $request, Tenant $tenant)
    {
        $gateway = TenantPaymentGateway::where('tenant_id', $tenant->id)
            ->where('provider', 'paystack')
            ->where('is_active', true)
            ->first();

        if (! $gateway) {
            return response()->json(['error' => 'Gateway not configured'], 404);
        }

        $signature = $request->header('x-paystack-signature');
        $secret = $gateway->api_key_encrypted; // Paystack uses secret key for hashing

        if (! $signature || $signature !== hash_hmac('sha512', $request->getContent(), (string) $secret)) {
            Log::warning('Merchant Paystack webhook signature verification failed', ['tenant' => $tenant->id]);

            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $event = $request->input('event');
        $data = $request->input('data');

        try {
            if ($event === 'charge.success') {
                $this->recordTransaction($tenant, 'paystack', (object) $data);
                $this->flagMissingCommissionSplit($tenant, (array) $data);
                $this->confirmEventRegistrationIfApplicable($tenant, (array) ($data['metadata'] ?? []), $data['reference'] ?? null, (int) ($data['amount'] ?? 0), (int) ($data['fees'] ?? 0), mb_strtoupper((string) ($data['currency'] ?? 'GHS')), 'paystack', is_numeric($data['fees_split']['subaccount'] ?? null) ? (int) $data['fees_split']['subaccount'] : null);
                $this->confirmMemberDuesIfApplicable($tenant, (array) $data);
            } elseif ($event === 'charge.failed') {
                $reference = $data['reference'] ?? null;

                // Webhook delivery is not ordered: a retried charge.failed can
                // land after the matching charge.success. Never downgrade a
                // charge that already succeeded.
                $alreadySucceeded = MerchantTransaction::where('tenant_id', $tenant->id)
                    ->where('provider', 'paystack')
                    ->where('provider_transaction_id', $reference)
                    ->where('status', 'succeeded')
                    ->exists();

                if ($alreadySucceeded) {
                    Log::info('Ignored a stale Paystack charge.failed webhook for an already-succeeded charge', [
                        'tenant' => $tenant->id,
                        'reference' => $reference,
                    ]);
                } else {
                    $this->recordTransaction($tenant, 'paystack', (object) $data, 'failed');
                }
            }
        } catch (Throwable $e) {
            Log::error('Merchant Paystack webhook processing failed', [
                'tenant' => $tenant->id,
                'event' => $event,
                'reference' => $data['reference'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * The charge reference is the PaymentIntent, because that is what a refund
     * is issued against.
     */
    private function confirmStripeSession(Tenant $tenant, TenantPaymentGateway $gateway, object $session): void
    {
        $currency = mb_strtoupper((string) $session->currency);
        $paymentIntent = (string) ($session->payment_intent ?? $session->id);
        $fee = app(\App\Services\Payment\StripeConnect::class)
            ->gatewayFor($gateway)
            ->processingFeeFor($paymentIntent, $currency);

        $metadata = $session->metadata ? $session->metadata->toArray() : [];

        $this->recordTransaction($tenant, 'stripe', $session);

        if (($metadata['type'] ?? null) === 'event_contribution') {
            app(\App\Services\Events\EventContributionService::class)->confirmContributionPayment($paymentIntent, [
                'metadata' => $metadata,
                'amount' => (int) $session->amount_total,
                'fees' => $fee,
                'provider' => 'stripe',
            ]);

            return;
        }

        $this->confirmEventRegistrationIfApplicable(
            $tenant,
            $metadata,
            $paymentIntent,
            (int) $session->amount_total,
            $fee,
            $currency,
            'stripe',
        );
    }

    /**
     * Checkout records the subaccount it split a charge with in the charge's
     * metadata, which Paystack returns untouched. If the charge's own payload
     * names no split at all, flag it for follow-up. Paystack documents the
     * `split` object only for split groups and the subaccount fields are not
     * documented for this case, so either shape is accepted, and a miss is a
     * warning to look at rather than a verdict: the money has already moved.
     *
     * @param  array<string, mixed>  $data
     */
    private function flagMissingCommissionSplit(Tenant $tenant, array $data): void
    {
        $expected = $data['metadata']['commission_split'] ?? null;

        if (! filled($expected)) {
            return;
        }

        $reported = array_filter([
            $data['subaccount']['subaccount_code'] ?? null,
            ...array_column((array) ($data['split']['subaccounts'] ?? []), 'subaccount'),
            ...array_column((array) ($data['split']['subaccounts'] ?? []), 'subaccount_code'),
        ]);

        if (in_array($expected, $reported, true)) {
            return;
        }

        Log::warning('A split ticket charge did not name the commission subaccount in its payload', [
            'tenant' => $tenant->id,
            'reference' => $data['reference'] ?? null,
            'expected' => $expected,
            'payload_keys' => array_keys($data),
        ]);
    }

    /**
     * If this charge was for an event ticket (carried in the checkout
     * metadata), confirm the matching registration. Idempotent: a
     * registration already confirmed is left untouched, so the gateway's
     * at-least-once webhook delivery can't double-process it.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function confirmEventRegistrationIfApplicable(Tenant $tenant, array $metadata, ?string $reference, int $amount, int $gatewayFeeAmount, string $currency, string $provider, ?int $splitReceived = null): void
    {
        $registrationId = $metadata['event_registration_id'] ?? null;
        if (! $registrationId) {
            return;
        }

        $registration = EventRegistration::where('tenant_id', $tenant->id)
            ->where('id', $registrationId)
            ->first();

        if (! $registration || $registration->isConfirmed()) {
            return;
        }

        $eventModel = $registration->event;
        /*
         * $amount is what the gateway actually collected. When the organizer
         * bears the commission that is exactly the ticket price, but when the
         * attendee bears it the receipt is larger by the commission — so the
         * ticket price comes from the registration rather than the receipt,
         * and must not be overwritten with it.
         */
        $ticketAmount = $eventModel->effectiveFeeBearer() === \App\Services\Finance\PlatformFeeResolver::BEARER_ATTENDEE
            ? (int) $registration->amount
            : $amount;

        $commissionAmount = app(\App\Services\Finance\FeeCalculator::class)->for($eventModel, $ticketAmount)->platformFee;

        // Payment stands in for email verification: the checkout link was sent
        // to this address and the gateway receipts it there too.
        $registration->email_verified_at ??= now();
        $registration->issueTicket();
        $registration->fill([
            'status' => EventRegistration::STATUS_CONFIRMED,
            'payment_reference' => $reference,
            'amount' => $ticketAmount,
            'charged_amount' => $amount,
            'gateway_fee_amount' => $gatewayFeeAmount,
            'currency' => $currency,
        ]);
        $registration->save();
        app(\App\Services\Notifications\EventRuleTriggerService::class)->registrationCompleted($registration);

        // The organizer holds the money, so the books carry only the commission:
        // what reached us with the payment, what was netted against credit we
        // owed them, and any remainder they owe. Checkout recorded the first
        // two in the metadata it sent to the gateway.
        $settlement = self::commissionSettlement($tenant, $currency, $provider, $metadata, $commissionAmount, $splitReceived);

        app(\App\Services\Finance\LedgerService::class)->recordOwnGatewayTicketSale(
            $eventModel,
            $registration,
            $amount,
            $commissionAmount,
            $reference,
            $gatewayFeeAmount,
            $provider,
            $settlement['received'],
            $settlement['credit'],
        );

        app(\App\Services\Tenancy\FeatureMeteringService::class)->recordUsage($tenant, 'event_registrations');
        app(\App\Services\Tenancy\FeatureMeteringService::class)->recordUsage($tenant, 'email_credits');

        Mail::to($registration->email)->queue(new EventRegistrationConfirmed($registration));
    }

    /**
     * If this charge was for member dues, confirm the charges it paid. The
     * charge reached this organisation's own Paystack account, so it is
     * booked as an own-gateway payment (commission only), and only for this
     * organisation's own members: the webhook is signed with a key the
     * organisation holds, so its metadata must name this organisation.
     *
     * @param  array<string, mixed>  $data
     */
    private function confirmMemberDuesIfApplicable(Tenant $tenant, array $data): void
    {
        $metadata = \App\Http\Controllers\Billing\WebhookController::metadata($data['metadata'] ?? null);
        $reference = (string) ($data['reference'] ?? '');

        if (($metadata['type'] ?? null) !== 'member_dues' || $reference === '') {
            return;
        }

        if ((string) ($metadata['tenant_id'] ?? '') !== (string) $tenant->id
            || ($metadata['source'] ?? null) !== config('services.paystack.metadata_source')) {
            Log::warning('dues.payment.foreign_webhook', [
                'tenant' => $tenant->id,
                'reference' => $reference,
                'metadata_tenant' => $metadata['tenant_id'] ?? null,
            ]);

            return;
        }

        app(\App\Services\Dues\DuesPaymentConfirmer::class)->confirm($reference, [
            'metadata' => $metadata,
            'amount' => (int) ($data['amount'] ?? 0),
            'fees' => (int) ($data['fees'] ?? 0),
            'currency' => mb_strtoupper((string) ($data['currency'] ?? '')),
            'provider' => 'paystack',
            'settled_by' => \App\Services\Dues\DuesPaymentConfirmer::SETTLED_BY_OWN_GATEWAY,
            'split_received' => is_numeric($data['fees_split']['subaccount'] ?? null) ? (int) $data['fees_split']['subaccount'] : null,
        ]);
    }

    private function recordTransaction(Tenant $tenant, string $provider, $data, string $status = 'succeeded'): void
    {
        MerchantTransaction::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'provider' => $provider,
                'provider_transaction_id' => $provider === 'stripe' ? ($data->payment_intent ?? $data->id) : $data->reference,
            ],
            [
                'amount' => $provider === 'stripe' ? $data->amount_total : ($data->amount),
                'currency' => mb_strtoupper((string) $provider === 'stripe' ? $data->currency : ($data->currency ?? 'NGN')),
                'status' => $status,
                'type' => 'payment',
                'customer_email' => $provider === 'stripe' ? ($data->customer_details->email ?? null) : ($data->customer['email'] ?? null),
                'customer_name' => $provider === 'stripe' ? ($data->customer_details->name ?? null) : mb_trim(($data->customer['first_name'] ?? '').' '.($data->customer['last_name'] ?? '')),
                'description' => $provider === 'stripe' ? 'Stripe Checkout' : 'Paystack Charge',
                'meta' => $data instanceof \Stripe\StripeObject ? $data->toArray() : (array) $data,
            ]
        );
    }
}
