<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Mail\Events\EventRegistrationConfirmed;
use App\Models\EventLedgerEntry;
use App\Models\EventPayout;
use App\Models\EventRegistration;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class SettlementWebhookController extends Controller
{
    /**
     * Handles both platform_default charge confirmations and payout
     * transfer confirmations — both originate from Purpledot's own
     * Paystack account, so both are verified against the platform's own
     * secret key. Paystack has no separate webhook-signing secret (unlike
     * Stripe): it signs the raw body with the same secret API key used to
     * make requests, so this must match PaystackSettlementGateway's key,
     * not a distinct "webhook secret" value.
     */
    public function handle(Request $request)
    {
        $signature = $request->header('x-paystack-signature');
        $secret = config('services.settlement.paystack.secret_key');

        if (! $signature || ! $secret || $signature !== hash_hmac('sha512', $request->getContent(), (string) $secret)) {
            Log::warning('Settlement webhook signature verification failed');

            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $event = $request->input('event');
        $data = $request->input('data');

        try {
            match ($event) {
                'charge.success' => $this->confirmPlatformDefaultCharge((array) ($data['metadata'] ?? []), $data['reference'] ?? null, (int) ($data['amount'] ?? 0), (int) ($data['fees'] ?? 0), mb_strtoupper((string) ($data['currency'] ?? 'GHS'))),
                'transfer.success', 'transfer.failed' => $this->confirmTransfer($event, (string) ($data['reference'] ?? ''), $data),
                default => null,
            };
        } catch (Throwable $e) {
            Log::error('Settlement webhook processing failed', ['event' => $event, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }

        return response()->json(['status' => 'success']);
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
        $commissionAmount = (int) round($amount * $eventModel->effectivePlatformFeePercentage() / 100);

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

        app(\App\Services\Tenancy\FeatureMeteringService::class)->recordUsage($tenant, 'event_registrations');
        app(\App\Services\Tenancy\FeatureMeteringService::class)->recordUsage($tenant, 'email_credits');

        Mail::to($registration->email)->send(new EventRegistrationConfirmed($registration));
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

            return;
        }

        $payout->update([
            'status' => EventPayout::STATUS_FAILED,
            'failure_reason' => (string) ($data['reason'] ?? 'Transfer failed'),
        ]);
    }
}
