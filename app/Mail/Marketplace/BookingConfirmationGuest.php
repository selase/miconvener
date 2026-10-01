<?php

declare(strict_types=1);

namespace App\Mail\Marketplace;

use App\Models\VenueBooking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class BookingConfirmationGuest extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public VenueBooking $booking,
        public string $bookingUrl
    ) {}

    public function envelope(): Envelope
    {
        $subject = "Booking Confirmation [{$this->booking->booking_reference}] — {$this->booking->listing->title}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.marketplace.booking-confirmation-guest',
            with: [
                'booking' => $this->booking,
                'bookingUrl' => $this->bookingUrl,
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
