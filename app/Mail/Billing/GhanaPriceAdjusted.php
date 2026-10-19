<?php

declare(strict_types=1);

namespace App\Mail\Billing;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells an organisation that paid the Ghana price with a card issued outside
 * Ghana what happened: the difference to the USD price was charged to the same
 * card, or could not be, and from now on it pays in USD.
 */
final class GhanaPriceAdjusted extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public const string TOPPED_UP = 'topped_up';

    public const string TOP_UP_FAILED = 'top_up_failed';

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $outcome,
        public readonly string $item,
        public readonly string $amountDisplay,
        public readonly string $billingUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "About the price you paid for {$this->item}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.billing.ghana-price-adjusted');
    }
}
