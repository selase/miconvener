<?php

declare(strict_types=1);

namespace App\Mail\Events;

use App\Mail\Concerns\BrandedForTenant;
use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class AutomatedNotificationMail extends Mailable implements ShouldQueue
{
    use BrandedForTenant;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Event $event,
        public string $recipientName,
        public string $emailSubject,
        public string $renderedBody,
        public ?string $actionUrl = null,
        public ?string $actionLabel = null
    ) {}

    public function envelope(): Envelope
    {
        return $this->brandedEnvelope($this->emailSubject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.events.automated-notification',
        );
    }

    protected function brandingEvent(): Event
    {
        return $this->event;
    }
}
