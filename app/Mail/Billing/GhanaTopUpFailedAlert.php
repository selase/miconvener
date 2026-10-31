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
 * For platform finance: a Ghana price paid by a foreign card could not be
 * brought up to the USD price, or there was no USD price to compare to. The
 * organisation keeps what it paid for; someone decides what, if anything, to do.
 */
final class GhanaTopUpFailedAlert extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $reference,
        public readonly string $outcome,
        public readonly string $detail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Ghana price needs a look: {$this->tenant->name}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.billing.ghana-top-up-failed-alert');
    }
}
