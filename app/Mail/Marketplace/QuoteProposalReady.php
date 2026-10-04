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
 * Tells a planner the vendor's itemized proposal is ready to review and accept.
 */
final class QuoteProposalReady extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public MarketplaceQuote $quote,
        public string $quoteUrl
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your quote from {$this->quote->shop->name} is ready [{$this->quote->quote_reference}]");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.marketplace.quote-proposal-ready');
    }
}
