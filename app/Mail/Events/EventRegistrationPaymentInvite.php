<?php

declare(strict_types=1);

namespace App\Mail\Events;

use App\Models\EventRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class EventRegistrationPaymentInvite extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  'approved'|'promoted'  $reason
     */
    public function __construct(public EventRegistration $registration, public string $reason) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Complete your payment for {$this->registration->event->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.events.registration-payment-invite',
            with: [
                'event' => $this->registration->event,
                'registration' => $this->registration,
                'reason' => $this->reason,
                'checkoutUrl' => route('public.events.checkout', [
                    'subdomain' => $this->registration->tenant->slug,
                    'event' => $this->registration->event->slug,
                    'registration' => $this->registration->id,
                ]),
            ],
        );
    }
}
