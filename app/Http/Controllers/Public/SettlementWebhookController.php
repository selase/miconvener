<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Billing\WebhookController as BillingWebhookController;
use App\Http\Controllers\Controller;
use App\Mail\Events\EventRegistrationConfirmed;
use App\Models\EventLedgerEntry;
use App\Models\EventPayout;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Services\Billing\SubscriptionProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class SettlementWebhookController extends Controller
{
    /**
     * The single platform-level Paystack webhook entry point.
     *
     * Paystack allows one webhook URL per business account, while this platform
     * receives two unrelated streams on its own accounts: event ticket charges
     * and payout transfers for platform-default settlement, and subscription
     * billing for the SaaS product itself. Routing both through one endpoint
     * means the same URL works whether those streams share a single Paystack
     * account or are split across two.
     *
     * Paystack has no separate webhook-signing secret (unlike Stripe): it signs
     * the raw body with the same secret API key used to make requests. Each
     * stream is therefore verified against its own key, and whichever key
     * matches tells us which account the event came from.
     */
    public function handle(Request $request, BillingWebhookController $billing, SubscriptionProvisioningService $provisioningService)
    {
        $signature = (string) $request->header('x-paystack-signature');
        $body = $request->getContent();

        $settlementKey = (string) config('services.settlement.paystack.secret_key');
        $billingKey = (string) config('services.paystack.secret_key');

        $settlementMatches = $settlementKey !== '' && hash_equals(hash_hmac('sha512', $body, $settlementKey), $signature);
        $billingMatches = $billingKey !== '' && hash_equals(hash_hmac('sha512', $body, $billingKey), $signature);

        if ($signature === '' || (! $settlementMatches && ! $billingMatches)) {
            Log::warning('Platform Paystack webhook signature verification failed');

            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $event = $request->input('event');
        $data = $request->input('data');

        $metadata = (array) ($data['metadata'] ?? []);

        // The platform's Paystack account is shared with other applications, so
        // this one webhook URL receives their events too. Acting on a charge that
        // is not ours would be quietly destructive: the billing handler resolves a
        // tenant by customer email, so another application's payment from someone
        // who also has an account here would provision them a subscription they
        // never bought. Transfers are exempt because ownership is already proven
        // by the payout reference, which only matches a payout this platform
        // created.
        if (! $this->isOurs($event, $metadata)) {
            Log::info('Ignoring Paystack event belonging to another application', [
                'event' => $event,
                'source' => $metadata['source'] ?? null,
            ]);

            return response()->json(['status' => 'ignored']);
        }

        // Only ticket charges and payout transfers belong to settlement. Anything
        // else is subscription billing, which was previously dropped on the floor
        // here: the match arm's default returned 200 and did nothing, so pointing
        // the account's single webhook URL at this endpoint silently discarded
        // every subscription event.
        if (! $this->isSettlementEvent($event, $metadata)) {
            return $billing->handlePaystack($request, $provisioningService);
        }

        if (! $settlementMatches) {
            Log::warning('Settlement event was not signed with the settlement key', ['event' => $event]);

            return response()->json(['error' => 'Invalid signature'], 400);
        }

        try {
            match ($event) {
                'charge.success' => $this->confirmPlatformDefaultCharge($metadata, $data['reference'] ?? null, (int) ($data['amount'] ?? 0), (int) ($data['fees'] ?? 0), mb_strtoupper((string) ($data['currency'] ?? 'GHS'))),
                'transfer.success', 'transfer.failed' => $this->confirmTransfer($event, (string) ($data['reference'] ?? ''), $data),
                default => null,
            };
        } catch (Throwable $e) {
            Log::error('Settlement webhook processing failed', ['event' => $event, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Whether this event was started by this application.
     *
     * A transfer carries no metadata of ours, but its reference is one this
     * platform generated, and confirmTransfer() already ignores a reference that
     * matches no payout here -- so transfers are safe without a marker.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function isOurs(?string $event, array $metadata): bool
    {
        if ($event === 'transfer.success' || $event === 'transfer.failed') {
            return true;
        }

        return ($metadata['source'] ?? null) === config('services.paystack.metadata_source');
    }

    /**
     * A charge belongs to settlement only when it carries event ticket metadata;
     * a subscription charge from the billing account looks otherwise identical.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function isSettlementEvent(?string $event, array $metadata): bool
    {
        if ($event === 'transfer.success' || $event === 'transfer.failed') {
            return true;
        }

        return $event === 'charge.success' && ($metadata['type'] ?? null) === 'event_ticket';
    }

    private function confirmPlatformDefaultCharge(array $metadata, ?string $reference, int $amount, int $gatewayFeeAmount, string $currency): void
    {
        $tenantId = $metadata['tenant_id'] ?? null;
        $registrationId = $metadata['event_registration_id'] ?? null;
        if (! $tenantId || ! $registrationId) {
            return;
        }

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            return;
        }

        $registration = EventRegistration::where('tenant_id', $tenant->id)
            ->where('id', $registrationId)
            ->first();

        if (! $registration || $registration->isConfirmed()) {
            return;
        }

        $eventModel = $registration->event;
        $commissionAmount = app(\App\Services\Finance\FeeCalculator::class)->for($eventModel, $amount)->platformFee;

        // Payment stands in for email verification: the checkout link was sent
        // to this address and the gateway receipts it there too.
        $registration->email_verified_at ??= now();
        $registration->issueTicket();
        $registration->fill([
            'status' => EventRegistration::STATUS_CONFIRMED,
            'payment_reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
        ]);
        $registration->save();

        EventLedgerEntry::create([
            'tenant_id' => $tenant->id,
            'event_id' => $eventModel->id,
            'type' => EventLedgerEntry::TYPE_CHARGE,
            'registration_id' => $registration->id,
            'gross_amount' => $amount,
            'gateway_fee_amount' => $gatewayFeeAmount,
            'commission_amount' => $commissionAmount,
            'net_amount' => $amount - $gatewayFeeAmount - $commissionAmount,
            'currency' => $currency,
            'provider' => 'paystack',
            'provider_reference' => $reference,
        ]);

        app(\App\Services\Finance\LedgerService::class)->recordTicketSale(
            $eventModel,
            $registration,
            $amount,
            $commissionAmount,
            $reference
        );

        app(\App\Services\Tenancy\FeatureMeteringService::class)->recordUsage($tenant, 'event_registrations');
        app(\App\Services\Tenancy\FeatureMeteringService::class)->recordUsage($tenant, 'email_credits');

        // Queued, not sent inline: the ticket and its ledger entry are already
        // committed by this point, so a slow or failing mail transport must not
        // turn a successful charge into a 500 and a provider retry.
        Mail::to($registration->email)->queue(new EventRegistrationConfirmed($registration));
    }

    private function confirmTransfer(string $event, string $reference, array $data): void
    {
        if ($reference === '') {
            return;
        }

        $payout = EventPayout::where('provider_reference', $reference)->first();
        if (! $payout || $payout->status !== EventPayout::STATUS_PROCESSING) {
            // Already processed (idempotent redelivery) or not a payout this
            // webhook is tracking — a no-op either way.
            return;
        }

        if ($event === 'transfer.success') {
            $payout->update(['status' => EventPayout::STATUS_PAID, 'paid_at' => now()]);

            EventLedgerEntry::create([
                'tenant_id' => $payout->tenant_id,
                'event_id' => $payout->event_id,
                'type' => EventLedgerEntry::TYPE_PAYOUT,
                'payout_id' => $payout->id,
                'gross_amount' => $payout->amount,
                'gateway_fee_amount' => 0,
                'commission_amount' => 0,
                'net_amount' => $payout->amount,
                'currency' => $payout->event->currency,
                'provider' => 'paystack',
                'provider_reference' => $reference,
            ]);

            // Without this the organizer payable is only ever debited on paths
            // that happen to be instrumented, so the balance drifts upward.
            app(\App\Services\Finance\LedgerService::class)->recordPayout(
                $payout->event,
                (int) $payout->amount,
                $reference,
                $payout
            );

            return;
        }

        $payout->update([
            'status' => EventPayout::STATUS_FAILED,
            'failure_reason' => (string) ($data['reason'] ?? 'Transfer failed'),
        ]);
    }
}
