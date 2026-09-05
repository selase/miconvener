<?php

declare(strict_types=1);

namespace App\Mail\Events;

use App\Models\EventRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class EventRegistrationRejected extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public EventRegistration $registration) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Update on your registration for {$this->registration->event->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.events.registration-rejected',
            with: [
                'event' => $this->registration->event,
                'registration' => $this->registration,
            ],
        );
    }
}
