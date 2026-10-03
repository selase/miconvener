<?php

declare(strict_types=1);

namespace App\Mail\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSession;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class OccurrenceReminderMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Event $event,
        public EventSession $session,
        public EventRegistration $registration
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Upcoming: {$this->event->name} - {$this->session->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.events.occurrence-reminder',
        );
    }

    /**
     * @return array<int, mixed>
     */
    public function attachments(): array
    {
        return [];
    }
}
