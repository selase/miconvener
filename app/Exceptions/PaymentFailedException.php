<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class PaymentFailedException extends RuntimeException
{
    public function __construct(
        string $message = 'Payment processing failed.',
        public readonly ?string $provider = null,
        public readonly ?string $providerCode = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function fromProvider(string $provider, string $message, ?string $providerCode = null, ?Throwable $previous = null): self
    {
        return new self(
            message: "[{$provider}] {$message}",
            provider: $provider,
            providerCode: $providerCode,
            previous: $previous,
        );
    }
}
