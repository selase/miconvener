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
 * Asks a tenant whose payment method can't be charged automatically to pay
 * for the next period: before it starts, or during grace once it is overdue.
 */
final class RenewalReminderMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $planName,
        public readonly string $amountDisplay,
        public readonly string $dueOn,
        public readonly bool $overdue,
        public readonly ?string $movesToFreeOn,
        public readonly string $payUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->overdue
            ? "Your {$this->planName} plan payment is overdue"
            : "Your {$this->planName} plan renews on {$this->dueOn}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.billing.renewal-reminder');
    }
}
