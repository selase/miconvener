<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentFailedException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

final class PaystackGateway implements PaymentGateway
{
    private string $baseUrl = 'https://api.paystack.co';

    private readonly string $secret;

    /**
     * @var array{subaccount: string, platform_share: int}|null
     */
    private ?array $split = null;

    private int $creditApplied = 0;

    public function __construct(?array $config = null)
    {
        $this->secret = $config['secret_key'] ?? config('services.paystack.secret_key') ?? '';
    }

    /**
     * A copy of this gateway whose one-time checkouts are split with a
     * subaccount. Paystack's transaction_charge is the flat amount that stays
     * with the main account (here the tenant), so the platform's share is
     * what is left for the subaccount. Fees come out of the main account's
     * portion (bearer "account"), leaving the platform's commission intact.
     */
    public function withSplit(string $subaccountCode, int $platformShare): self
    {
        $clone = clone $this;
        $clone->split = ['subaccount' => $subaccountCode, 'platform_share' => $platformShare];

        return $clone;
    }

    /**
     * A copy of this gateway recording that part of the platform's commission
     * on this charge is netted against credit it owes the organizer, so only
     * the remainder is routed to the platform.
     */
    public function withCreditApplied(int $amount): self
    {
        $clone = clone $this;
        $clone->creditApplied = $amount;

        return $clone;
    }

    public function commissionCreditApplied(): int
    {
        return $this->creditApplied;
    }

    /**
     * The subaccount this gateway's checkouts are split with, if any.
     */
    public function splitSubaccount(): ?string
    {
        return $this->split['subaccount'] ?? null;
    }

    /**
     * The subaccount's details, or null when this account has no such
     * subaccount.
     *
     * @return array<string, mixed>|null
     */
    public function subaccount(string $code): ?array
    {
        try {
            $response = Http::withToken($this->secret)->timeout(10)->get("{$this->baseUrl}/subaccount/".rawurlencode($code));

            if ($response->status() === 404) {
                return null;
            }

            $response->throw();
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('paystack', $e->getMessage(), previous: $e);
        }

        return $response->json('status') === true ? (array) $response->json('data') : null;
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

    /** @param array<string, mixed> $metadata */
    public function createStoreCheckoutSession(string $email, int $amount, string $currency, string $redirectUrl, array $metadata, string $reference): string
    {
        try {
            $response = Http::withToken($this->secret)->timeout(10)->post("{$this->baseUrl}/transaction/initialize", [
                'email' => $email, 'amount' => $amount, 'currency' => $currency,
                'callback_url' => $redirectUrl, 'metadata' => $metadata, 'reference' => $reference,
            ])->throw();
            $url = $response->json('data.authorization_url');
            if (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'checkout.paystack.com') {
                throw PaymentFailedException::fromProvider('paystack', 'Checkout initialization needs review.');
            }

            return $url;
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('paystack', 'Checkout initialization could not be confirmed.', previous: $e);
        }
    }

    public function createOneTimeCheckoutSession(string $customerId, int $amount, string $currency, string $redirectUrl, array $metadata = [], ?string $reference = null): string
    {
        try {
            $customerResponse = Http::withToken($this->secret)->timeout(10)->get("{$this->baseUrl}/customer/{$customerId}")->throw();
            $email = $customerResponse->json('data.email');

            $payload = [
                'email' => $email,
                'amount' => $amount,
                'currency' => $currency,
                'callback_url' => $redirectUrl,
                'metadata' => $metadata,
            ];

            if ($reference !== null) {
                $payload['reference'] = $reference;
            }

            // The channels Paystack may offer, when the checkout restricts them
            // (a Ghana price that must be paid by Ghana mobile money or card).
            if (is_array($metadata['allowed_channels'] ?? null) && $metadata['allowed_channels'] !== []) {
                $payload['channels'] = array_values($metadata['allowed_channels']);
            }

            if ($this->split !== null) {
                $payload['subaccount'] = $this->split['subaccount'];
                $payload['transaction_charge'] = $amount - $this->split['platform_share'];
                $payload['bearer'] = 'account';
            }

            $response = Http::withToken($this->secret)->timeout(10)->post("{$this->baseUrl}/transaction/initialize", $payload)->throw();

            return $response->json('data.authorization_url');
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('paystack', $e->getMessage(), previous: $e);
        }
    }

    /**
     * Charge a saved, reusable authorization without the customer present.
     *
     * A declined charge is a normal answer (status "failed"), not an exception;
     * only a rejected request (bad authorization, duplicate reference) throws.
     *
     * @param  array<string, mixed>  $metadata  Sent as an object so the webhook routes it by `source`.
     * @return array{status: string, reference: string, gateway_response: ?string, authorization: array<string, mixed>, channel: ?string, paid_at: ?string, amount: int, currency: string}
     */
    public function chargeAuthorization(string $email, int $amountMinor, string $currency, string $authorizationCode, string $reference, array $metadata): array
    {
        try {
            $response = Http::withToken($this->secret)->timeout(30)->post("{$this->baseUrl}/transaction/charge_authorization", [
                'email' => $email,
                'amount' => $amountMinor,
                'currency' => $currency,
                'authorization_code' => $authorizationCode,
                'reference' => $reference,
                'metadata' => $metadata,
            ])->throw();
        } catch (RequestException $e) {
            throw PaymentFailedException::fromProvider('paystack', (string) ($e->response->json('message') ?? $e->getMessage()), previous: $e);
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('paystack', $e->getMessage(), previous: $e);
        }

        $data = (array) $response->json('data', []);

        return [
            'status' => (string) ($data['status'] ?? 'failed'),
            'reference' => (string) ($data['reference'] ?? $reference),
            'gateway_response' => $data['gateway_response'] ?? null,
            'authorization' => (array) ($data['authorization'] ?? []),
            'channel' => $data['channel'] ?? null,
            'paid_at' => $data['paid_at'] ?? $data['transaction_date'] ?? null,
            'amount' => (int) ($data['amount'] ?? $amountMinor),
            'currency' => mb_strtoupper((string) ($data['currency'] ?? $currency)),
        ];
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

            $refundId = $response->json('data.id');

            return $refundId === null ? 'pending' : (string) $refundId;
        } catch (RequestException $e) {
            // Paystack's error body carries a machine-readable "code" (e.g.
            // transaction_reversed) alongside the human-readable message.
            // Surface it via providerCode so callers can react to specific
            // failure reasons instead of only having free-text to match on.
            throw PaymentFailedException::fromProvider(
                'paystack',
                $e->getMessage(),
                providerCode: $e->response->json('code'),
                previous: $e,
            );
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('paystack', $e->getMessage(), previous: $e);
        }
    }

    /** @return array{reference:string, status:string} */
    public function requestGivingRefund(string $reference, int $amount, string $operationKey): array
    {
        return $this->requestTaggedRefund($reference, $amount, 'giving:'.$operationKey);
    }

    /** @return array<string, mixed> */
    public function verifyGivingRefund(string $refundId, string $reference, string $operationKey): array
    {
        if ($refundId === '') {
            return ['status' => 'unknown'];
        }
        $data = Http::withToken($this->secret)->timeout(10)->get("{$this->baseUrl}/refund/".rawurlencode($refundId))->throw()->json('data');
        if ((string) ($data['id'] ?? '') !== $refundId || ($data['merchant_note'] ?? '') !== 'giving:'.$operationKey) {
            return ['status' => 'unknown'];
        }

        return ['status' => (string) ($data['status'] ?? 'unknown'), 'amount' => (int) ($data['amount'] ?? 0),
            'currency' => mb_strtoupper((string) ($data['currency'] ?? '')), 'gateway_credit' => 0, 'commission_returned' => 0,
            'transaction_id' => is_array($data['transaction'] ?? null) ? (string) ($data['transaction']['id'] ?? '') : (string) ($data['transaction'] ?? '')];
    }

    /** @return array{reference:string, status:string} */
    public function requestCommerceRefund(string $reference, int $amount, string $operationKey): array
    {
        if ($amount < 1 || $amount > 9007199254740991 || ! \Illuminate\Support\Str::isUuid($operationKey)) {
            throw new InvalidArgumentException('A full commerce refund needs a valid amount and operation key.');
        }

        return $this->requestTaggedRefund($reference, $amount, 'store:'.$operationKey);
    }

    /** @return array<string, mixed> */
    public function verifyCommerceRefund(string $refundId, string $reference, string $operationKey): array
    {
        if (! preg_match('/^[0-9]{1,30}$/D', $refundId)) {
            return ['status' => 'unknown'];
        }
        $payment = $this->verifyTransaction($reference, true);
        if (! ($payment['reference_reported'] ?? false) || ($payment['reference'] ?? null) !== $reference || ($payment['status'] ?? null) !== 'success') {
            return ['status' => 'unknown'];
        }
        $data = Http::withToken($this->secret)->timeout(10)->get("{$this->baseUrl}/refund/".rawurlencode($refundId))->throw()->json('data');
        $transactionId = is_array($data['transaction'] ?? null) ? (string) ($data['transaction']['id'] ?? '') : (string) ($data['transaction'] ?? '');
        if ((string) ($data['id'] ?? '') !== $refundId || ($data['merchant_note'] ?? '') !== 'store:'.$operationKey || $transactionId !== (string) ($payment['transaction_id'] ?? '')) {
            return ['status' => 'unknown'];
        }

        return ['status' => (string) ($data['status'] ?? 'unknown'), 'refund_id' => $refundId, 'payment_reference' => $reference,
            'amount' => is_int($data['amount'] ?? null) ? $data['amount'] : null,
            'currency' => mb_strtoupper((string) ($data['currency'] ?? '')), 'transaction_id' => $transactionId];
    }

    public function findCommerceRefund(string $reference, string $operationKey, int $amount, string $currency): ?string
    {
        $payment = $this->verifyTransaction($reference, true);
        if (! ($payment['reference_reported'] ?? false) || ($payment['reference'] ?? null) !== $reference || ! filled($payment['transaction_id'] ?? null)) {
            return null;
        }
        for ($page = 1; $page <= 10; $page++) {
            $response = Http::withToken($this->secret)->timeout(10)->get("{$this->baseUrl}/refund", ['transaction' => $payment['transaction_id'], 'perPage' => 100, 'page' => $page])->throw();
            $rows = $response->json('data');
            if (! is_array($rows)) {
                return null;
            }
            foreach ($rows as $row) {
                if (is_array($row) && ($row['merchant_note'] ?? '') === 'store:'.$operationKey && ($row['amount'] ?? null) === $amount
                    && mb_strtoupper((string) ($row['currency'] ?? '')) === $currency && preg_match('/^[0-9]{1,30}$/D', (string) ($row['id'] ?? ''))) {
                    return (string) $row['id'];
                }
            }
            $pages = $response->json('meta.pageCount');
            if ((is_int($pages) && $page >= $pages) || ($pages === null && count($rows) < 100)) {
                return null;
            }
        }

        return null;
    }

    public function verifyTransaction(string $reference, bool $strictEvidence = false): array
    {
        try {
            $response = Http::withToken($this->secret)->timeout(10)->get("{$this->baseUrl}/transaction/verify/{$reference}")->throw();
            $data = $response->json('data');

            return [
                'status' => $data['status'],
                'metadata' => $data['metadata'] ?? [],
                'transaction_id' => $data['id'],
                'reference' => (string) ($data['reference'] ?? $reference),
                'reference_reported' => isset($data['reference']) && is_string($data['reference']),
                'type' => isset($data['plan']) && $data['plan'] ? 'subscription' : 'payment',
                'amount' => $data['amount'] ?? 0,
                'fees' => (int) ($data['fees'] ?? 0),
                'fees_reported' => isset($data['fees']),
                ...($strictEvidence ? ['fees_valid' => is_int($data['fees'] ?? null), 'currency_reported' => is_string($data['currency'] ?? null)] : []),
                'fees_split' => (array) ($data['fees_split'] ?? []),
                'currency' => mb_strtolower($data['currency'] ?? 'ghs'),
                'channel' => $data['channel'] ?? null,
                'authorization' => (array) ($data['authorization'] ?? []),
                'paid_at' => $data['paid_at'] ?? null,
                'customer_email' => $data['customer']['email'] ?? null,
            ];
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('paystack', $e->getMessage(), previous: $e);
        }
    }

    /** @return array{reference:string, status:string} */
    private function requestTaggedRefund(string $reference, int $amount, string $note): array
    {
        $data = Http::withToken($this->secret)->timeout(10)->post("{$this->baseUrl}/refund", [
            'transaction' => $reference, 'amount' => $amount, 'merchant_note' => $note,
        ])->throw()->json('data');

        return ['reference' => isset($data['id']) ? (string) $data['id'] : '', 'status' => (string) ($data['status'] ?? 'pending')];
    }
}
