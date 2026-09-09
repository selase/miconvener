<?php

declare(strict_types=1);

namespace App\Mail\Events;

use App\Models\EventRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the address the ticket has just left. A transfer is irreversible, so
 * the person losing it should hear about it from us rather than discover it at
 * the door.
 */
final class EventTicketTransferred extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public EventRegistration $registration,
        public string $previousName,
        public string $newName,
        public string $newEmail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your ticket for {$this->registration->event->name} was transferred",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.events.ticket-transferred',
            with: [
                'registration' => $this->registration,
                'event' => $this->registration->event,
                'previousName' => $this->previousName,
                'newName' => $this->newName,
                'newEmail' => $this->newEmail,
            ],
        );
    }
}
