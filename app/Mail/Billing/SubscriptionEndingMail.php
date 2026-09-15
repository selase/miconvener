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

final class SubscriptionEndingMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $planName,
        public readonly ?string $endsOn,
        public readonly string $billingUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your {$this->planName} plan won't renew");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.billing.subscription-ending');
    }
}
