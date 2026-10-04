<?php

declare(strict_types=1);

namespace App\Mail\Marketplace;

use App\Models\MarketplaceQuote;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a vendor a planner has asked for an itemized quote.
 */
final class NewQuoteRequestNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public MarketplaceQuote $quote,
        public string $inboxUrl
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "New quote request [{$this->quote->quote_reference}]: {$this->quote->listing->title}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.marketplace.new-quote-request');
    }
}
