<?php

declare(strict_types=1);

namespace App\Services\Payment;

/**
 * The outcome of asking Paystack whether a tenant's commission subaccount
 * routes to the platform. An unreachable Paystack is neither a pass nor a
 * fail, so it is kept apart: it must not mark a good subaccount as broken.
 */
final readonly class CommissionSplitCheck
{
    private function __construct(
        public bool $ok,
        public bool $inconclusive,
        public ?string $reason,
    ) {}

    public static function valid(): self
    {
        return new self(true, false, null);
    }

    public static function invalid(string $reason): self
    {
        return new self(false, false, $reason);
    }

    public static function inconclusive(string $reason): self
    {
        return new self(false, true, $reason);
    }
}
