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
                // A raster QR embedded as an inline attachment, because Gmail
                // strips SVG and Outlook cannot render it -- an SVG data URI
                // reaches the attendee as a broken image. The SVG stays as the
                // fallback for hosts with no raster backend.
                'qrPng' => QrCodeGenerator::png($this->registration->qr_token),
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
