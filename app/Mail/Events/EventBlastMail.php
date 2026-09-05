<?php

declare(strict_types=1);

namespace App\Mail\Events;

use App\Models\EventBlast;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class EventBlastMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public EventBlast $blast, public string $recipientName, public string $recipientId) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->blast->subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.events.blast',
            with: [
                'recipientName' => $this->recipientName,
                'event' => $this->blast->event,
                'bodyText' => $this->blast->body,
                'trackingUrl' => route('public.blasts.open', ['recipient' => $this->recipientId]),
            ],
        );
    }
}
