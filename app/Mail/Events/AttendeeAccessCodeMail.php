<?php

declare(strict_types=1);

namespace App\Mail\Events;

use App\Models\AttendeeAccessCode;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A one-time code proving someone holds an address, so they can see what is
 * theirs with this organiser.
 *
 * Sent under the platform's name with the organiser named in it: branding as
 * the organiser needs an event, and a code asked for at /my belongs to none.
 *
 * The code is a constructor argument, never read off a model. This mail is
 * queued, and a queued mailable's models are rebuilt from the database on the
 * worker, so anything held only in memory would arrive empty.
 */
final class AttendeeAccessCodeMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Tenant $tenant,
        public string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your code for {$this->tenant->name}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.events.attendee-access-code',
            with: [
                'organiser' => $this->tenant->name,
                'code' => $this->code,
                'minutes' => AttendeeAccessCode::TTL_MINUTES,
            ],
        );
    }
}
