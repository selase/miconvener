<?php

declare(strict_types=1);

namespace App\Mail\Marketplace;

use App\Models\VenueBooking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class QuoteSentNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public VenueBooking $booking,
        public string $checkoutUrl
    ) {}

    public function envelope(): Envelope
    {
        $subject = "Custom Venue Quote [{$this->booking->booking_reference}]: {$this->booking->listing->title}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.marketplace.quote-sent',
            with: [
                'booking' => $this->booking,
                'checkoutUrl' => $this->checkoutUrl,
            ]
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
