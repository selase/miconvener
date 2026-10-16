<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class CommunityContactNotice extends Mailable
{
    public function __construct(public string $organisationName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your membership contact details changed');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.community-contact-notice');
    }
}
