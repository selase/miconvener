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
 * Receipt for a payment a tenant made to MiConvener: a plan, an invoice or an
 * AI token pack. When the payment activated a plan, $activePlan names it.
 */
final class PaymentReceiptMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $description,
        public readonly string $amountDisplay,
        public readonly string $reference,
        public readonly string $paidOn,
        public readonly ?string $paymentMethod,
        public readonly ?string $activePlan,
        public readonly string $billingUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Payment received: {$this->amountDisplay}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.billing.payment-receipt');
    }
}
