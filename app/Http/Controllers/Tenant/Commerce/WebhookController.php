<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Commerce;

use App\Http\Controllers\Controller;
use App\Mail\Events\EventRegistrationConfirmed;
use App\Models\EventRegistration;
use App\Models\MerchantTransaction;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class WebhookController extends Controller
{
    /**
     * Handle Stripe Webhooks for Merchant accounts.
     */
    public function handleStripe(Request $request, Tenant $tenant)
    {
        $gateway = TenantPaymentGateway::where('tenant_id', $tenant->id)
            ->where('provider', 'stripe')
            ->where('is_active', true)
            ->first();

        if (! $gateway) {
            return response()->json(['error' => 'Gateway not configured'], 404);
        }

        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = $gateway->webhook_secret_encrypted;

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload, $sigHeader, $webhookSecret
            );
        } catch (Exception $e) {
            Log::error('Merchant Stripe webhook verification failed', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'Invalid signature'], 400);
        }

        switch ($event->type) {
            case 'checkout.session.completed':
                $session = $event->data->object;
                $this->recordTransaction($tenant, 'stripe', $session);
                break;

            case 'payment_intent.succeeded':
                $paymentIntent = $event->data->object;
                // Avoid double counting if using both
                break;
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
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $event = $request->input('event');
        $data = $request->input('data');

        if ($event === 'charge.success') {
            $this->recordTransaction($tenant, 'paystack', (object) $data);
            $this->confirmEventRegistrationIfApplicable($tenant, (array) ($data['metadata'] ?? []), $data['reference'] ?? null, (int) ($data['amount'] ?? 0), mb_strtoupper((string) ($data['currency'] ?? 'GHS')));
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * If this charge was for an event ticket (carried in the checkout
     * metadata), confirm the matching registration. Idempotent: a
     * registration already confirmed is left untouched, so Paystack's
     * at-least-once webhook delivery can't double-process it.
     */
    private function confirmEventRegistrationIfApplicable(Tenant $tenant, array $metadata, ?string $reference, int $amount, string $currency): void
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

        $registration->issueTicket();
        $registration->fill([
            'status' => EventRegistration::STATUS_CONFIRMED,
            'payment_reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
        ]);
        $registration->save();

        app(\App\Services\Tenancy\FeatureMeteringService::class)->recordUsage($tenant, 'event_registrations');

        Mail::to($registration->email)->send(new EventRegistrationConfirmed($registration));
    }

    private function recordTransaction(Tenant $tenant, string $provider, $data): void
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
                'status' => 'succeeded',
                'type' => 'payment',
                'customer_email' => $provider === 'stripe' ? ($data->customer_details->email ?? null) : ($data->customer['email'] ?? null),
                'customer_name' => $provider === 'stripe' ? ($data->customer_details->name ?? null) : mb_trim(($data->customer['first_name'] ?? '').' '.($data->customer['last_name'] ?? '')),
                'description' => $provider === 'stripe' ? 'Stripe Checkout' : 'Paystack Charge',
                'meta' => (array) $data,
            ]
        );
    }
}
