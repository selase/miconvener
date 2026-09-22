<?php

declare(strict_types=1);

namespace App\Mail\Events;

use App\Mail\Concerns\BrandedForTenant;
use App\Models\Event;
use App\Models\EventRegistrationTransfer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The code arrives as a constructor argument, never read off the transfer.
 * This mail is queued, and a queued mailable's models are rebuilt from the
 * database on the worker: a value held only in memory does not make the trip.
 */
final class EventTicketTransferCode extends Mailable
{
    use BrandedForTenant;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public EventRegistrationTransfer $transfer,
        public string $code,
    ) {}

    public function envelope(): Envelope
    {
        return $this->brandedEnvelope('Your code to transfer this ticket');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.events.ticket-transfer-code',
            with: [
                'transfer' => $this->transfer,
                'registration' => $this->transfer->registration,
                'event' => $this->transfer->registration->event,
                'code' => $this->code,
            ],
        );
    }

    protected function brandingEvent(): ?Event
    {
        return $this->transfer->registration?->event;
    }
}
