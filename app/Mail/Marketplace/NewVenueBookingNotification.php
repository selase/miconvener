<?php

declare(strict_types=1);

namespace App\Mail\Marketplace;

use App\Models\VenueBooking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class NewVenueBookingNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public VenueBooking $booking,
        public string $inboxUrl
    ) {}

    public function envelope(): Envelope
    {
        $subject = "New Venue Booking [{$this->booking->booking_reference}]: {$this->booking->listing->title}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.marketplace.new-booking-host',
            with: [
                'booking' => $this->booking,
                'inboxUrl' => $this->inboxUrl,
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
