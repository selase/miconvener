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

    /**
     * What became of the messages sent under one provider reference, keyed
     * by the recipient's number as international digits.
     *
     * Providers here have no delivery webhook, so this is asked for later
     * (see SmsDeliverySync). "pending" means the provider does not know yet.
     * Null means the report could not be had right now (not configured, the
     * provider is busy, or it answered with an error); ask again later.
     *
     * @return array<string, array{status: 'delivered'|'undelivered'|'pending', detail: ?string}>|null
     */
    public function deliveryReport(string $providerReference): ?array;
}
