<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The provider answered a checkout initialisation with a definitive refusal, so
 * no transaction was created and the buyer may safely try again. Anything less
 * certain (a timeout, a malformed reply, a duplicate reference) stays a
 * PaymentFailedException and is left unresolved.
 */
final class CheckoutRejectedException extends RuntimeException
{
    public static function fromProvider(string $provider, string $message, ?Throwable $previous = null): self
    {
        return new self("[{$provider}] {$message}", 0, $previous);
    }
}
