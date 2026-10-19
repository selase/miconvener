<?php

declare(strict_types=1);

namespace App\Mail\Venue;

use App\Models\VenueBooking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a staff member what became of their room request: approved, declined
 * (with the reason) or cancelled by the facility team.
 */
final class RoomRequestDecided extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public const string APPROVED = 'approved';

    public const string DECLINED = 'declined';

    public const string CANCELLED = 'cancelled';

    public function __construct(public readonly VenueBooking $booking, public readonly string $outcome, public readonly ?string $reason = null) {}

    public function envelope(): Envelope
    {
        $room = $this->booking->listing->title;

        return new Envelope(subject: match ($this->outcome) {
            self::APPROVED => "Your request for {$room} is approved",
            self::DECLINED => "Your request for {$room} was declined",
            default => "Your booking for {$room} was cancelled",
        });
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.venue.room-request-decided');
    }
}
