<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

final class ScheduledPayoutNoticeMail extends Mailable
{
    public function __construct(public string $noticeId, public string $kind, public string $reviewUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'MiConvener payout schedule update');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.scheduled-payout-notice');
    }

    public function headers(): Headers
    {
        return new Headers(messageId: 'scheduled-payout-'.$this->noticeId.'@miconvener.com');
    }
}
