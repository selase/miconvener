<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Services\Sms\SmsMessage;
use App\Services\Sms\SmsResult;

/**
 * Hands SMS to a provider. "Accepted" means the provider has taken the
 * message to send (they queue it); delivery itself is reported later.
 */
interface SmsGateway
{
    /**
     * @param  list<SmsMessage>  $messages
     * @return array<string, SmsResult> keyed by each message's reference
     */
    public function send(array $messages): array;

    /**
     * Whether this gateway can actually send. False for the stand-in used
     * when no provider is configured, so nothing sells SMS that cannot go.
     */
    public function isConfigured(): bool;
}
