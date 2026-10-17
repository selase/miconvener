<?php

declare(strict_types=1);

namespace Tests\Support;

use Stripe\HttpClient\ClientInterface;

final class GivingStripeHttp implements ClientInterface
{
    /** @var list<array<string, mixed>> */
    public array $requests = [];

    /** @var array<string, mixed> */
    public array $metadata = [];

    public int $applicationFee = 100;

    public int $feeRefunded = 0;

    /** @var array<string, array<string, mixed>> */
    public array $refunds = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params];
        if ($method === 'post' && str_ends_with($absUrl, '/checkout/sessions')) {
            $this->metadata = $params['metadata'];
            $this->applicationFee = (int) ($params['payment_intent_data']['application_fee_amount'] ?? 0);
            $body = ['object' => 'checkout.session', 'id' => 'cs_giving', 'url' => 'https://checkout.stripe.com/giving', 'payment_intent' => null];
        } elseif (str_ends_with($absUrl, '/checkout/sessions/cs_giving')) {
            $body = ['object' => 'checkout.session', 'id' => 'cs_giving', 'payment_status' => 'paid', 'payment_intent' => 'pi_giving', 'amount_total' => 10000, 'currency' => 'ghs', 'metadata' => $this->metadata];
        } elseif (str_ends_with($absUrl, '/payment_intents/pi_giving')) {
            $body = ['object' => 'payment_intent', 'id' => 'pi_giving', 'status' => 'succeeded', 'amount_received' => 10000, 'currency' => 'ghs', 'application_fee_amount' => $this->applicationFee, 'metadata' => $this->metadata,
                'latest_charge' => ['object' => 'charge', 'id' => 'ch_giving', 'application_fee' => 'fee_giving', 'balance_transaction' => ['object' => 'balance_transaction', 'id' => 'txn_giving', 'currency' => 'ghs', 'fee' => 200 + $this->applicationFee, 'fee_details' => [['type' => 'stripe_fee', 'amount' => 200, 'currency' => 'ghs'], ['type' => 'application_fee', 'amount' => $this->applicationFee, 'currency' => 'ghs']]]]];
        } elseif ($method === 'post' && str_ends_with($absUrl, '/refunds')) {
            $id = 're_giving_'.(count($this->refunds) + 1);
            $body = ['object' => 'refund', 'id' => $id, 'status' => 'succeeded', 'payment_intent' => 'pi_giving', 'amount' => (int) $params['amount'], 'currency' => 'ghs', 'metadata' => $params['metadata'], 'balance_transaction' => null];
            $this->refunds[$id] = $body;
        } elseif (str_contains($absUrl, '/refunds/re_giving_')) {
            $body = $this->refunds[basename($absUrl)];
        } elseif (str_ends_with($absUrl, '/application_fees/fee_giving')) {
            $body = ['object' => 'application_fee', 'id' => 'fee_giving', 'amount_refunded' => $this->feeRefunded];
        } else {
            return [json_encode(['error' => ['message' => 'Unexpected synthetic Stripe request']]), 404, []];
        }

        return [json_encode($body), 200, []];
    }
}
