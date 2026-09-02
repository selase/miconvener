<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentFailedException;
use Throwable;

final class StripeGateway implements PaymentGateway
{
    public function __construct(
        private \Stripe\StripeClient $stripe,
        private ?array $config = null
    ) {
        if ($this->config) {
            $this->stripe = new \Stripe\StripeClient($this->config['secret_key']);
        }
    }

    public function createCustomer(string $email, string $name): string
    {
        try {
            $customer = $this->stripe->customers->create([
                'email' => $email,
                'name' => $name,
            ]);

            return $customer->id;
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('stripe', $e->getMessage(), previous: $e);
        }
    }

    public function createCheckoutSession(string $customerId, string $planId, string $redirectUrl): string
    {
        try {
            $session = $this->stripe->checkout->sessions->create([
                'customer' => $customerId,
                'line_items' => [
                    [
                        'price' => $planId,
                        'quantity' => 1,
                    ],
                ],
                'mode' => 'subscription',
                'success_url' => $redirectUrl.'?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $redirectUrl,
            ]);

            return $session->url ?? '';
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('stripe', $e->getMessage(), previous: $e);
        }
    }

    public function createOneTimeCheckoutSession(string $customerId, int $amount, string $currency, string $redirectUrl, array $metadata = []): string
    {
        try {
            $session = $this->stripe->checkout->sessions->create([
                'customer' => $customerId,
                'line_items' => [
                    [
                        'price_data' => [
                            'currency' => $currency,
                            'product_data' => [
                                'name' => $metadata['description'] ?? 'Payment',
                            ],
                            'unit_amount' => $amount, // cents
                        ],
                        'quantity' => 1,
                    ],
                ],
                'mode' => 'payment',
                'metadata' => $metadata,
                'success_url' => $redirectUrl.'&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $redirectUrl,
            ]);

            return $session->url ?? '';
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('stripe', $e->getMessage(), previous: $e);
        }
    }

    public function charge(string $customerId, int $amount, string $currency, array $options = []): string
    {
        try {
            $paymentIntent = $this->stripe->paymentIntents->create([
                'amount' => $amount,
                'currency' => $currency,
                'customer' => $customerId,
                'confirm' => true,
                'payment_method' => $options['payment_method'] ?? 'pm_card_visa',
                'return_url' => 'https://example.com/return',
            ]);

            return $paymentIntent->id;
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('stripe', $e->getMessage(), previous: $e);
        }
    }

    public function subscriptionDetails(string $subscriptionId): array
    {
        try {
            $sub = $this->stripe->subscriptions->retrieve($subscriptionId);

            return [
                'status' => $sub->status,
                'current_period_end' => $sub->current_period_end,
            ];
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('stripe', $e->getMessage(), previous: $e);
        }
    }

    public function refund(string $transactionId, ?int $amount = null): string
    {
        try {
            $params = ['payment_intent' => $transactionId];
            if ($amount) {
                $params['amount'] = $amount;
            }
            $refund = $this->stripe->refunds->create($params);

            return $refund->id;
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('stripe', $e->getMessage(), previous: $e);
        }
    }

    public function verifyTransaction(string $reference): array
    {
        try {
            if (str_starts_with($reference, 'cs_')) {
                $session = $this->stripe->checkout->sessions->retrieve($reference);

                return [
                    'status' => $session->payment_status === 'paid' ? 'success' : $session->payment_status,
                    'metadata' => $session->metadata?->toArray() ?? [],
                    'transaction_id' => $session->payment_intent,
                    'type' => $session->mode,
                ];
            }

            $pi = $this->stripe->paymentIntents->retrieve($reference);

            return [
                'status' => $pi->status,
                'metadata' => $pi->metadata?->toArray() ?? [],
                'transaction_id' => $pi->id,
                'type' => 'payment',
            ];
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('stripe', $e->getMessage(), previous: $e);
        }
    }
}
