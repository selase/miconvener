<?php

declare(strict_types=1);

namespace App\Mail\Events;

use App\Models\EventRegistrationTransfer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class EventTicketTransferCode extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public EventRegistrationTransfer $transfer) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your code to transfer this ticket',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.events.ticket-transfer-code',
            with: [
                'transfer' => $this->transfer,
                'registration' => $this->transfer->registration,
                'event' => $this->transfer->registration->event,
                'code' => $this->transfer->plainCode,
            ],
        );
    }
}
