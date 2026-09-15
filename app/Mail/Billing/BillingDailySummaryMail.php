<?php

declare(strict_types=1);

namespace App\Mail\Billing;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent directly, not queued: it reports on the queue and must still arrive when
 * the queue is what broke.
 */
final class BillingDailySummaryMail extends Mailable
{
    /**
     * @param  array<string, mixed>  $summary  From BillingDailySummary::build().
     */
    public function __construct(public readonly array $summary) {}

    public function envelope(): Envelope
    {
        $needsAttention = $this->summary['renewal_run']['stale']
            || $this->summary['failed_jobs'] > 0
            || $this->summary['webhook_failures']['processing'] > 0
            || $this->summary['past_due'] !== [];

        return new Envelope(subject: ($needsAttention ? 'Needs attention: ' : '').'MiConvener billing for '.now()->format('j F Y'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.billing.daily-summary');
    }
}
