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
 * Tells an organization that online Paystack payments have stopped because the
 * commission subaccount no longer checks out, so they hear it from us before
 * an attendee does.
 */
final class CommissionSplitBrokenMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $reason,
        public readonly string $settingsUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Paystack payments for {$this->tenant->name} are paused");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.payments.commission-split-broken');
    }
}
