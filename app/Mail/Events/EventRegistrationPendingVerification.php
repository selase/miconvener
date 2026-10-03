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

final class EventRegistrationPendingVerification extends Mailable
{
    use BrandedForTenant;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public EventRegistration $registration,
        public string $statusUrl = '',
    ) {
        if ($this->statusUrl === '') {
            $this->statusUrl = route('public.events.checkout', [
                'subdomain' => $this->registration->tenant->slug ?? 'demo',
                'event' => $this->registration->event->slug,
                'registration' => $this->registration->id,
            ]);
        }
    }

    public function envelope(): Envelope
    {
        return $this->brandedEnvelope("Payment Proof Received for {$this->registration->event->name}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.events.registration-pending-verification',
            with: [
                'event' => $this->registration->event,
                'registration' => $this->registration,
                'statusUrl' => $this->statusUrl,
            ],
        );
    }

    protected function brandingEvent(): ?Event
    {
        return $this->registration->event;
    }
}
