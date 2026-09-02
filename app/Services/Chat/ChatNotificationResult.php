<?php

declare(strict_types=1);

namespace App\Services\Chat;

final readonly class ChatNotificationResult
{
    /**
     * @param  list<string>  $errorMessages
     */
    public function __construct(
        public int $sent = 0,
        public int $failed = 0,
        public int $skipped = 0,
        public array $errorMessages = [],
    ) {}
}
