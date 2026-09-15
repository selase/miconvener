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

final class PaymentFailedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $amountDisplay,
        public readonly ?string $retryOn,
        public readonly string $billingUrl,
        public readonly ?string $movesToFreeOn = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Action needed: your {$this->tenant->name} payment didn't go through");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.billing.payment-failed');
    }
}
