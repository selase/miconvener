<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentFailedException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class PaystackGateway implements PaymentGateway
{
    private string $baseUrl = 'https://api.paystack.co';

    private readonly string $secret;

    public function __construct(?array $config = null)
    {
        $this->secret = $config['secret_key'] ?? config('services.paystack.secret_key') ?? '';
    }

    public function createCustomer(string $email, string $name): string
    {
        try {
            $response = Http::withToken($this->secret)->timeout(10)->post("{$this->baseUrl}/customer", [
                'email' => $email,
                'first_name' => $name,
            ])->throw();

            return $response->json('data.customer_code');
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('paystack', $e->getMessage(), previous: $e);
        }
    }

    public function createCheckoutSession(string $customerId, string $planId, string $redirectUrl): string
    {
        try {
            $customerResponse = Http::withToken($this->secret)->timeout(10)->get("{$this->baseUrl}/customer/{$customerId}")->throw();
            $email = $customerResponse->json('data.email');

            $response = Http::withToken($this->secret)->timeout(10)->post("{$this->baseUrl}/transaction/initialize", [
                'email' => $email,
                'plan' => $planId,
                'callback_url' => $redirectUrl,
            ])->throw();

            return $response->json('data.authorization_url');
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('paystack', $e->getMessage(), previous: $e);
        }
    }

    public function createOneTimeCheckoutSession(string $customerId, int $amount, string $currency, string $redirectUrl, array $metadata = []): string
    {
        try {
            $customerResponse = Http::withToken($this->secret)->timeout(10)->get("{$this->baseUrl}/customer/{$customerId}")->throw();
            $email = $customerResponse->json('data.email');

            $response = Http::withToken($this->secret)->timeout(10)->post("{$this->baseUrl}/transaction/initialize", [
                'email' => $email,
                'amount' => $amount,
                'currency' => $currency,
                'callback_url' => $redirectUrl,
                'metadata' => $metadata,
            ])->throw();

            return $response->json('data.authorization_url');
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('paystack', $e->getMessage(), previous: $e);
        }
    }

    public function charge(string $customerId, int $amount, string $currency, array $options = []): string
    {
        if (! isset($options['authorization_code'])) {
            throw PaymentFailedException::fromProvider('paystack', "Paystack charge requires 'authorization_code' in options.");
        }

        try {
            $customerResponse = Http::withToken($this->secret)->timeout(10)->get("{$this->baseUrl}/customer/{$customerId}")->throw();
            $email = $customerResponse->json('data.email');

            $response = Http::withToken($this->secret)->timeout(10)->post("{$this->baseUrl}/transaction/charge_authorization", [
                'email' => $email,
                'amount' => $amount,
                'authorization_code' => $options['authorization_code'],
                'currency' => $currency,
            ])->throw();

            return $response->json('data.reference');
        } catch (PaymentFailedException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('paystack', $e->getMessage(), previous: $e);
        }
    }

    public function subscriptionDetails(string $subscriptionId): array
    {
        try {
            $response = Http::withToken($this->secret)->timeout(10)->get("{$this->baseUrl}/subscription/{$subscriptionId}")->throw();

            return [
                'status' => $response->json('data.status'),
                'current_period_end' => strtotime((string) $response->json('data.next_payment_date')),
            ];
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('paystack', $e->getMessage(), previous: $e);
        }
    }

    public function refund(string $transactionId, ?int $amount = null): string
    {
        try {
            $payload = ['transaction' => $transactionId];
            if ($amount) {
                $payload['amount'] = $amount;
            }

            $response = Http::withToken($this->secret)->timeout(10)->post("{$this->baseUrl}/refund", $payload)->throw();

            return $response->json('data.id') ?? 'pending';
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('paystack', $e->getMessage(), previous: $e);
        }
    }

    public function verifyTransaction(string $reference): array
    {
        try {
            $response = Http::withToken($this->secret)->timeout(10)->get("{$this->baseUrl}/transaction/verify/{$reference}")->throw();
            $data = $response->json('data');

            return [
                'status' => $data['status'],
                'metadata' => $data['metadata'] ?? [],
                'transaction_id' => $data['id'],
                'type' => isset($data['plan']) && $data['plan'] ? 'subscription' : 'payment',
                'amount' => $data['amount'] ?? 0,
                'currency' => mb_strtolower($data['currency'] ?? 'ghs'),
            ];
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('paystack', $e->getMessage(), previous: $e);
        }
    }
}
