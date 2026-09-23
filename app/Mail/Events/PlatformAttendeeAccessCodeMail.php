<?php

declare(strict_types=1);

namespace App\Mail\Events;

use App\Models\PlatformAttendeeAccessCode;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A one-time code proving someone controls an address across the platform.
 * Transactional platform authentication mail, never metered against tenant credits.
 */
final class PlatformAttendeeAccessCodeMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your MiConvener sign-in code');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.events.platform-attendee-access-code',
            with: [
                'code' => $this->code,
                'minutes' => PlatformAttendeeAccessCode::TTL_MINUTES,
            ],
        );
    }
}
