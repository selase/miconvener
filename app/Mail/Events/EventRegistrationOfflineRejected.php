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

final class EventRegistrationOfflineRejected extends Mailable
{
    use BrandedForTenant;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public EventRegistration $registration,
        public string $reason = '',
        public string $checkoutUrl = '',
    ) {
        if ($this->checkoutUrl === '') {
            $this->checkoutUrl = route('public.events.checkout', [
                'subdomain' => $this->registration->tenant->slug ?? 'demo',
                'event' => $this->registration->event->slug,
                'registration' => $this->registration->id,
            ]);
        }
    }

    public function envelope(): Envelope
    {
        return $this->brandedEnvelope("Payment Verification Update for {$this->registration->event->name}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.events.registration-offline-rejected',
            with: [
                'event' => $this->registration->event,
                'registration' => $this->registration,
                'reason' => $this->reason,
                'checkoutUrl' => $this->checkoutUrl,
            ],
        );
    }

    protected function brandingEvent(): ?Event
    {
        return $this->registration->event;
    }
}
