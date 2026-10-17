<?php

declare(strict_types=1);

namespace App\Services\Community;

final class InboxReaderKey
{
    public function forSubject(string $subject): string
    {
        $key = hash_hmac('sha256', 'community-inbox-reader:v1', (string) config('app.key'), true);

        return hash_hmac('sha256', $subject, $key);
    }
}
