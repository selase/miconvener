<?php

declare(strict_types=1);

namespace App\Mail\Events;

use App\Mail\Concerns\BrandedForTenant;
use App\Models\Event;
use App\Models\EventAbstract;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class AbstractDecisionNotificationMail extends Mailable
{
    use BrandedForTenant;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Event $event,
        public EventAbstract $abstract,
        public ?string $decisionNotes = null
    ) {}

    public function envelope(): Envelope
    {
        $statusText = match ($this->abstract->status) {
            EventAbstract::STATUS_ACCEPTED_ORAL => 'Accepted for Oral Presentation',
            EventAbstract::STATUS_ACCEPTED_POSTER => 'Accepted for Poster Presentation',
            EventAbstract::STATUS_REJECTED => 'Decision Notification',
            default => 'Review Status Update',
        };

        return $this->brandedEnvelope("[{$this->event->title}] Abstract {$this->abstract->code}: {$statusText}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.events.abstract-decision',
            with: [
                'event' => $this->event,
                'abstract' => $this->abstract,
                'notes' => $this->decisionNotes,
            ],
        );
    }

    protected function brandingEvent(): Event
    {
        return $this->event;
    }
}
