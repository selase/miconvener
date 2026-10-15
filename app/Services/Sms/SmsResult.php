<?php

declare(strict_types=1);

namespace App\Services\Sms;

final readonly class SmsResult
{
    /**
     * @param  ?int  $retryAfter  Seconds the provider asked us to wait when it
     *                            turned the request away for volume; the
     *                            message was not refused and should be retried.
     * @param  bool  $uncertain  The provider may have queued it; do not refund or blindly retry.
     */
    public function __construct(
        public bool $accepted,
        public ?string $providerReference = null,
        public ?string $error = null,
        public ?int $retryAfter = null,
        public bool $uncertain = false,
    ) {}

    public static function throttled(int $retryAfter): self
    {
        return new self(false, error: "SMS provider is busy; retrying in {$retryAfter}s.", retryAfter: $retryAfter);
    }

    public static function accepted(?string $providerReference): self
    {
        return new self(true, $providerReference);
    }

    public static function refused(string $error): self
    {
        return new self(false, error: $error);
    }

    public static function unknown(string $error): self
    {
        return new self(false, error: $error, uncertain: true);
    }

    public function isThrottled(): bool
    {
        return $this->retryAfter !== null;
    }
}
