<?php

declare(strict_types=1);

namespace App\Mail\Events;

use App\Mail\Concerns\BrandedForTenant;
use App\Models\Event;
use App\Models\EventSpeaker;
use App\Models\Speaker;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class SpeakerPortalInvitationMail extends Mailable
{
    use BrandedForTenant;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Event $event,
        public Speaker $speaker,
        public EventSpeaker $eventSpeaker
    ) {}

    public function envelope(): Envelope
    {
        return $this->brandedEnvelope("Speaker invitation: {$this->event->name}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.events.speaker-portal-invitation',
            with: [
                'event' => $this->event,
                'speaker' => $this->speaker,
                'portalUrl' => $this->eventSpeaker->portalUrl(),
            ],
        );
    }

    /**
     * @return array<int, mixed>
     */
    public function attachments(): array
    {
        return [];
    }

    protected function brandingEvent(): Event
    {
        return $this->event;
    }
}
