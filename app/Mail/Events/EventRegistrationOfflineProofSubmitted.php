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

final class EventRegistrationOfflineProofSubmitted extends Mailable
{
    use BrandedForTenant;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public EventRegistration $registration,
        public string $consoleUrl = '',
    ) {
        if ($this->consoleUrl === '') {
            $this->consoleUrl = route('tenant.events.show', [
                'subdomain' => $this->registration->tenant->slug ?? 'demo',
                'event' => $this->registration->event->slug,
            ]);
        }
    }

    public function envelope(): Envelope
    {
        return $this->brandedEnvelope("New Offline Payment Proof Submitted: {$this->registration->full_name}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.events.registration-offline-proof-submitted',
            with: [
                'event' => $this->registration->event,
                'registration' => $this->registration,
                'consoleUrl' => $this->consoleUrl,
            ],
        );
    }

    protected function brandingEvent(): ?Event
    {
        return $this->registration->event;
    }
}
