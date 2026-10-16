<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Contracts\SmsGateway;
use App\Services\Sms\SmsMessage;
use App\Services\Sms\SmsResult;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

/** Transport only; domain services own claims, consent, quotas and histories. */
final class AnnouncementTransport
{
    public function __construct(private SmsGateway $sms) {}

    public function email(string $address, Mailable $mail): void
    {
        Mail::to($address)->send($mail);
    }

    /** @param list<SmsMessage> $messages
     * @return array<string, SmsResult>
     */
    public function sms(array $messages): array
    {
        return $this->sms->send($messages);
    }
}
