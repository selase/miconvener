<?php

declare(strict_types=1);

namespace App\Contracts;

interface PaymentGateway
{
    public function createCustomer(string $email, string $name): string;

    public function createCheckoutSession(string $customerId, string $planId, string $redirectUrl): string;

    public function createOneTimeCheckoutSession(string $customerId, int $amount, string $currency, string $redirectUrl, array $metadata = []): string;

    public function charge(string $customerId, int $amount, string $currency, array $options = []): string;

    public function subscriptionDetails(string $subscriptionId): array;

    public function refund(string $transactionId, ?int $amount = null): string;

    public function verifyTransaction(string $reference): array;
}
