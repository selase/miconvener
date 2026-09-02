<?php

declare(strict_types=1);

namespace App\Services\Billing;

use Illuminate\Support\Facades\Http;

final readonly class PaystackSimulatorService
{
    private string $secretKey;

    private string $webhookUrl;

    public function __construct(?string $webhookUrl = null)
    {
        $this->secretKey = config('services.paystack.secret_key', 'test_secret');
        // Default to local herd url or app url
        $this->webhookUrl = $webhookUrl ?? config('app.url').'/api/webhooks/paystack';
    }

    /**
     * Simulate a successful charge webhook.
     */
    public function simulateSuccessfulCharge(string $customerCode, string $subscriptionCode, ?string $reference = null): \Illuminate\Http\Client\Response
    {
        $reference ??= 'sim_'.bin2hex(random_bytes(8));

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'id' => random_int(100000, 999999),
                'reference' => $reference,
                'status' => 'success',
                'amount' => 10000,
                'currency' => 'GHS',
                'customer' => [
                    'customer_code' => $customerCode,
                    'email' => 'simulator@example.com',
                ],
                'plan' => [
                    'plan_code' => 'PLN_pro',
                ],
                'subscription' => [
                    'subscription_code' => $subscriptionCode,
                ],
            ],
        ];

        return $this->dispatchRawWebhook($payload);
    }

    /**
     * Simulate a failed payment for a subscription.
     */
    public function simulateFailedCharge(string $customerCode, string $subscriptionCode): \Illuminate\Http\Client\Response
    {
        $payload = [
            'event' => 'invoice.payment_failed',
            'data' => [
                'customer' => [
                    'customer_code' => $customerCode,
                ],
                'subscription' => [
                    'subscription_code' => $subscriptionCode,
                    'status' => 'active',
                ],
            ],
        ];

        return $this->dispatchRawWebhook($payload);
    }

    /**
     * Simulate Paystack giving up and disabling a subscription.
     */
    public function simulateSubscriptionDisabled(string $customerCode, string $subscriptionCode): \Illuminate\Http\Client\Response
    {
        $payload = [
            'event' => 'subscription.disable',
            'data' => [
                'customer' => [
                    'customer_code' => $customerCode,
                ],
                'subscription_code' => $subscriptionCode,
            ],
        ];

        return $this->dispatchRawWebhook($payload);
    }

    /**
     * Compute HMAC SHA512 signature for Paystack payload.
     */
    private function computeSignature(string $payload): string
    {
        return hash_hmac('sha512', $payload, $this->secretKey);
    }

    /**
     * Fire the webhook using raw string to perfectly match signature hashing.
     */
    private function dispatchRawWebhook(array $payload): \Illuminate\Http\Client\Response
    {
        $jsonPayload = json_encode($payload);
        $signature = $this->computeSignature($jsonPayload);

        return Http::withHeaders([
            'x-paystack-signature' => $signature,
            'Content-Type' => 'application/json',
        ])->send('POST', $this->webhookUrl, [
            'body' => $jsonPayload,
        ]);
    }
}
