<?php

declare(strict_types=1);

namespace App\Mail\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Asks someone who enrolled in two-factor authentication before recovery codes
 * existed to generate a set.
 *
 * Deliberately carries no codes: they are a second factor, and this inbox is
 * also where password resets land. The codes are shown once, in the browser,
 * to someone already signed in.
 */
final class SetUpRecoveryCodesMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $firstName,
        public readonly string $organizationName,
        public readonly string $accountUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Set up your two-factor recovery codes');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.auth.set-up-recovery-codes');
    }
}
