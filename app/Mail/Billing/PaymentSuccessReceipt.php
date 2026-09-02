<?php

declare(strict_types=1);

namespace App\Mail\Billing;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class PaymentSuccessReceipt extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(public \App\Models\Tenant $tenant)
    {
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Payment Success Receipt',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        // Using a dynamic html string for simplicity, or we could map to a real view
        return new Content(
            htmlString: "<h1>Payment Successful</h1><p>Your subscription for {$this->tenant->name} has been renewed automatically.</p>",
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
