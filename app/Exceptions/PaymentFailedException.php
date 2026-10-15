<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\Client\RequestException;
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

    /**
     * The provider's own explanation, for showing to a person. An HTTP error
     * from the provider carries its JSON body, whose `message` (for Paystack,
     * "Transfer is not currently awaiting OTP" and the like) reads far better
     * than the exception text, which embeds the raw body and is truncated.
     * Falls back to the exception message when there is no such field.
     */
    public function providerMessage(): string
    {
        $previous = $this->getPrevious();

        if ($previous instanceof RequestException) {
            $message = $previous->response->json('message');

            if (is_string($message) && mb_trim($message) !== '') {
                return mb_trim($message);
            }
        }

        return $this->getMessage();
    }
}
