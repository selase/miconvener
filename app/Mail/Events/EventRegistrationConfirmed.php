<?php

declare(strict_types=1);

namespace App\Mail\Events;

use App\Models\EventRegistration;
use App\Services\Events\QrCodeGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class EventRegistrationConfirmed extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public EventRegistration $registration) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You're confirmed for {$this->registration->event->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.events.registration-confirmed',
            with: [
                'event' => $this->registration->event,
                'registration' => $this->registration,
                'qrImage' => QrCodeGenerator::svgDataUri($this->registration->qr_token),
                'portalUrl' => route('public.events.confirmation', [
                    'subdomain' => $this->registration->tenant->slug,
                    'event' => $this->registration->event->slug,
                    'registration' => $this->registration->id,
                ]),
            ],
        );
    }
}
