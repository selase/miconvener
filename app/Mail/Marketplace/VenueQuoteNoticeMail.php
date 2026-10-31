<?php

declare(strict_types=1);

namespace App\Mail\Marketplace;

use App\Models\VenueFinancialDecision;
use App\Models\VenueQuoteNotice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a venue requester that a final quote is ready to review or was withdrawn.
 * It carries the amounts and deadlines the host entered, never the platform
 * commission, and no payment link: payment starts from the verified booking page.
 */
final class VenueQuoteNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public VenueQuoteNotice $notice, public string $bookingUrl) {}

    public function envelope(): Envelope
    {
        $reference = $this->notice->quote->booking->booking_reference;
        $headline = $this->notice->kind === VenueQuoteNotice::WITHDRAWN ? 'The host withdrew your quote' : 'Your final venue quote is ready';

        return new Envelope(subject: "{$headline} [{$reference}]");
    }

    public function content(): Content
    {
        $quote = $this->notice->quote;
        $booking = $quote->booking->loadMissing(['listing', 'shop']);
        $timezone = $booking->approvalPolicy()['timezone'];
        $withdrawal = $this->notice->kind === VenueQuoteNotice::WITHDRAWN
            ? VenueFinancialDecision::query()->where('quote_id', $quote->id)->where('decision', VenueFinancialDecision::WITHDRAWN)->first()
            : null;

        return new Content(
            markdown: 'emails.marketplace.venue-quote-notice',
            with: [
                'kind' => $this->notice->kind, 'booking' => $booking, 'quote' => $quote, 'bookingUrl' => $this->bookingUrl, 'timezone' => $timezone,
                'expiresAt' => $quote->expires_at->copy()->setTimezone($timezone),
                'balanceDueAt' => $quote->balance_due_at?->copy()->setTimezone($timezone),
                'securityDueAt' => $quote->security_due_at?->copy()->setTimezone($timezone),
                'withdrawnReason' => $withdrawal?->reason,
            ]
        );
    }
}
