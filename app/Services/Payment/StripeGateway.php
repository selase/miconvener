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
        if (isset($this->config['secret_key'])) {
            $this->stripe = new \Stripe\StripeClient($this->config['secret_key']);
        }
    }

    /**
     * Creates a hosted Checkout session on the tenant's connected account as a
     * direct charge, with the platform's commission taken as an application
     * fee. The money settles in the tenant's Stripe balance; only the fee
     * comes to the platform.
     *
     * @param  array<string, string>  $metadata
     */
    public function createConnectedCheckoutSession(
        string $email,
        int $amount,
        string $currency,
        string $description,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
        int $applicationFee,
    ): string {
        return $this->createConnectedCheckoutSessionResult($email, $amount, $currency, $description, $successUrl, $cancelUrl, $metadata, $applicationFee)['url'];
    }

    /**
     * @param  array<string, string>  $metadata
     * @return array{url:string, session_id:string, payment_intent:?string}
     */
    public function createConnectedCheckoutSessionResult(
        string $email,
        int $amount,
        string $currency,
        string $description,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
        int $applicationFee,
        ?string $idempotencyKey = null,
    ): array {
        $paymentIntentData = ['metadata' => $metadata];

        if ($applicationFee > 0) {
            $paymentIntentData['application_fee_amount'] = $applicationFee;
        }

        try {
            $session = $this->stripe->checkout->sessions->create([
                'mode' => 'payment',
                'customer_email' => $email,
                'line_items' => [[
                    'price_data' => [
                        'currency' => mb_strtolower($currency),
                        'product_data' => ['name' => $description],
                        'unit_amount' => $amount,
                    ],
                    'quantity' => 1,
                ]],
                'metadata' => $metadata,
                'payment_intent_data' => $paymentIntentData,
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
            ], $this->accountOptions() + ($idempotencyKey === null ? [] : ['idempotency_key' => $idempotencyKey]));

            return ['url' => $session->url ?? '', 'session_id' => $session->id ?? '', 'payment_intent' => is_string($session->payment_intent ?? null) ? $session->payment_intent : null];
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('stripe', $e->getMessage(), previous: $e);
        }
    }

    /**
     * What Stripe charged the tenant to process a payment, in the charge's own
     * currency, or 0 when it settled in a different currency or can't be read.
     * The fee is bookkeeping, so failing to read it never blocks a payment.
     */
    public function processingFeeFor(string $paymentIntentId, string $currency): int
    {
        try {
            $intent = $this->stripe->paymentIntents->retrieve(
                $paymentIntentId,
                ['expand' => ['latest_charge.balance_transaction']],
                $this->accountOptions(),
            );

            $transaction = $intent->latest_charge->balance_transaction ?? null;

            if (! is_object($transaction) || mb_strtolower((string) $transaction->currency) !== mb_strtolower($currency)) {
                return 0;
            }

            return max(0, (int) $transaction->fee);
        } catch (Throwable) {
            return 0;
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
            $options = $this->accountOptions();
            if ($options !== []) {
                // A refunded ticket gives the platform's commission back too.
                $params['refund_application_fee'] = true;
            }
            $refund = $this->stripe->refunds->create($params, $options);

            return $refund->id;
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('stripe', $e->getMessage(), previous: $e);
        }
    }

    /** @return array<string, mixed> */
    public function verifyConnectedGivingSession(string $sessionId): array
    {
        $session = $this->stripe->checkout->sessions->retrieve($sessionId, [], $this->accountOptions());
        if ($session->payment_status !== 'paid' || ! is_string($session->payment_intent)) {
            return ['status' => 'pending'];
        }
        $intent = $this->stripe->paymentIntents->retrieve($session->payment_intent, ['expand' => ['latest_charge.balance_transaction']], $this->accountOptions());
        $balance = is_object($intent->latest_charge) ? $intent->latest_charge->balance_transaction : null;
        $currency = mb_strtoupper((string) $session->currency);
        $fees = is_object($balance) && mb_strtoupper((string) $balance->currency) === $currency ? (int) $balance->fee : null;

        return ['status' => $intent->status === 'succeeded' && $intent->amount_received === $session->amount_total ? 'success' : 'pending',
            'metadata' => $session->metadata?->toArray() ?? [], 'amount' => (int) $session->amount_total, 'currency' => $currency,
            'fees' => $fees, 'commission_received' => (int) ($intent->application_fee_amount ?? 0),
            'session_id' => $session->id, 'provider_reference' => $intent->id];
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

    /**
     * Request options that aim a call at the tenant's connected account. The
     * platform's own key signs every call; the account header says whose
     * account it acts on.
     *
     * @return array{stripe_account?: string}
     */
    private function accountOptions(): array
    {
        $account = $this->config['stripe_account'] ?? null;

        return filled($account) ? ['stripe_account' => $account] : [];
    }
}
