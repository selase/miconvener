<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Contracts\SmsGateway;

/**
 * Used when no SMS provider is configured: every message is refused with a
 * reason, so callers record a failure instead of claiming it was sent.
 */
final class NullSmsGateway implements SmsGateway
{
    public function send(array $messages): array
    {
        $results = [];

        foreach ($messages as $message) {
            $results[$message->reference] = SmsResult::refused('SMS is not configured on this platform.');
        }

        return $results;
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function deliveryReport(string $providerReference): ?array
    {
        return null;
    }
}
