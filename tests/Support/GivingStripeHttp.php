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

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params];
        if ($method === 'post' && str_ends_with($absUrl, '/checkout/sessions')) {
            $this->metadata = $params['metadata'];
            $body = ['object' => 'checkout.session', 'id' => 'cs_giving', 'url' => 'https://checkout.stripe.com/giving', 'payment_intent' => null];
        } elseif (str_ends_with($absUrl, '/checkout/sessions/cs_giving')) {
            $body = ['object' => 'checkout.session', 'id' => 'cs_giving', 'payment_status' => 'paid', 'payment_intent' => 'pi_giving', 'amount_total' => 10000, 'currency' => 'ghs', 'metadata' => $this->metadata];
        } elseif (str_ends_with($absUrl, '/payment_intents/pi_giving')) {
            $body = ['object' => 'payment_intent', 'id' => 'pi_giving', 'status' => 'succeeded', 'amount_received' => 10000, 'currency' => 'ghs', 'application_fee_amount' => 100, 'metadata' => $this->metadata,
                'latest_charge' => ['object' => 'charge', 'id' => 'ch_giving', 'balance_transaction' => ['object' => 'balance_transaction', 'id' => 'txn_giving', 'currency' => 'ghs', 'fee' => 200]]];
        } else {
            return [json_encode(['error' => ['message' => 'Unexpected synthetic Stripe request']]), 404, []];
        }

        return [json_encode($body), 200, []];
    }
}
