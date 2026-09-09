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
 * Sent when someone asks for their ticket link back. Deliberately carries no
 * personal detail beyond the link itself: it is triggered by anyone who can
 * type an address, so it must be useless to a stranger who guessed one.
 */
final class EventTicketLink extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public EventRegistration $registration) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your ticket for {$this->registration->event->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.events.ticket-link',
            with: [
                'event' => $this->registration->event,
                'registration' => $this->registration,
                'portalUrl' => route('public.events.confirmation', [
                    'subdomain' => $this->registration->tenant->slug,
                    'event' => $this->registration->event->slug,
                    'registration' => $this->registration->id,
                ]),
            ],
        );
    }
}
