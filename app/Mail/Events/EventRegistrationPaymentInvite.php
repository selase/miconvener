<?php

declare(strict_types=1);

namespace App\Mail\Events;

use App\Mail\Concerns\BrandedForTenant;
use App\Models\Event;
use App\Models\EventRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

final class EventRegistrationPaymentInvite extends Mailable
{
    use BrandedForTenant;
    use Queueable;
    use SerializesModels;

    /**
     * @param  'approved'|'promoted'  $reason
     */
    public function __construct(public EventRegistration $registration, public string $reason) {}

    public function envelope(): Envelope
    {
        return $this->brandedEnvelope("Complete your payment for {$this->registration->event->name}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.events.registration-payment-invite',
            with: [
                'event' => $this->registration->event,
                'registration' => $this->registration,
                'reason' => $this->reason,
                // Signed, because following this link from the inbox it was
                // sent to is what entitles the payer to their own workspace
                // afterwards. An unsigned copy still pays; it just does not
                // hand out access.
                'checkoutUrl' => URL::signedRoute('public.events.checkout', [
                    'subdomain' => $this->registration->tenant->slug,
                    'event' => $this->registration->event->slug,
                    'registration' => $this->registration->id,
                ]),
            ],
        );
    }

    protected function brandingEvent(): ?Event
    {
        return $this->registration->event;
    }
}
