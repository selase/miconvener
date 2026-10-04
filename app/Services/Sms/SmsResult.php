<?php

declare(strict_types=1);

namespace App\Services\Sms;

final readonly class SmsResult
{
    public function __construct(
        public bool $accepted,
        public ?string $providerReference = null,
        public ?string $error = null,
    ) {}

    public static function accepted(?string $providerReference): self
    {
        return new self(true, $providerReference);
    }

    public static function refused(string $error): self
    {
        return new self(false, error: $error);
    }
}
