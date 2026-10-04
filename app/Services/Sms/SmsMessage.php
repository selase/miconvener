<?php

declare(strict_types=1);

namespace App\Services\Sms;

final readonly class SmsMessage
{
    /**
     * @param  string  $reference  Our id for the message, to match the result to it.
     */
    public function __construct(
        public string $to,
        public string $text,
        public string $reference,
    ) {}
}
