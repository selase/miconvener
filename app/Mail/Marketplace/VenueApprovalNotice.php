<?php

declare(strict_types=1);

namespace App\Mail\Marketplace;

use App\Models\VenueBookingAction;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a venue requester what the host decided. It never claims a
 * confirmed booking and carries no payment link.
 */
final class VenueApprovalNotice extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public VenueBookingAction $action,
        public string $bookingUrl,
    ) {}

    public function envelope(): Envelope
    {
        $booking = $this->action->booking;
        $headline = match ($this->action->action) {
            VenueBookingAction::ACTION_REQUESTED => 'We have your request',
            VenueBookingAction::ACTION_APPROVED, VenueBookingAction::ACTION_RENEWED => 'The host is holding your dates',
            VenueBookingAction::ACTION_DECLINED => 'The host could not accept your request',
            VenueBookingAction::ACTION_RELEASED => 'The host released your held dates',
            default => 'An update on your venue request',
        };

        return new Envelope(subject: "{$headline} [{$booking->booking_reference}]");
    }

    public function content(): Content
    {
        $booking = $this->action->booking->loadMissing(['listing', 'shop']);
        $timezone = $booking->approvalPolicy()['timezone'];

        return new Content(
            markdown: 'emails.marketplace.venue-approval-notice',
            with: [
                'booking' => $booking,
                'action' => $this->action,
                'bookingUrl' => $this->bookingUrl,
                'timezone' => $timezone,
                'startsAt' => $booking->starts_at->copy()->setTimezone($timezone),
                'endsAt' => $booking->ends_at->copy()->setTimezone($timezone),
                'holdUntil' => $this->action->hold_expires_at?->copy()->setTimezone($timezone),
            ]
        );
    }
}
