<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\StoreOrder;
use App\Models\StorePaymentAttempt;
use App\Services\Giving\GivingGateways;
use App\Services\Payment\PaystackGateway;
use Illuminate\Validation\ValidationException;

final class StoreGateways
{
    public function forOrder(StoreOrder $order): PaystackGateway
    {
        if ($order->provider !== 'paystack' || $order->settlement_mode !== 'platform_default' || $order->currency !== 'GHS'
            || ! filled(config('services.settlement.paystack.secret_key'))
            || ! hash_equals($order->account_fingerprint, hash('sha256', app(GivingGateways::class)->platformIdentity()))) {
            throw ValidationException::withMessages(['payment' => 'The original payment account needs operator review.']);
        }

        return new PaystackGateway(['secret_key' => config('services.settlement.paystack.secret_key')]);
    }

    public function forAttempt(StorePaymentAttempt $attempt): PaystackGateway
    {
        $attempt = StorePaymentAttempt::query()->with('order')->findOrFail($attempt->id);
        if ($attempt->provider !== $attempt->order->provider || $attempt->settlement_mode !== $attempt->order->settlement_mode
            || $attempt->currency !== $attempt->order->currency || ! hash_equals($attempt->account_fingerprint, $attempt->order->account_fingerprint)) {
            throw ValidationException::withMessages(['payment' => 'Payment account evidence needs operator review.']);
        }

        return $this->forOrder($attempt->order);
    }
}
