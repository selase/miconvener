<?php

declare(strict_types=1);

namespace App\Mail\Events;

use App\Models\EventRegistration;
use App\Services\Events\QrCodeGenerator;
use App\Services\Events\TicketPdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Throwable;

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

    /**
     * The ticket travels as a PDF as well as inline, because a venue is exactly
     * where a phone loses signal or runs flat and an attendee needs something
     * they can open offline or hand over on paper.
     *
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        try {
            $service = app(TicketPdfService::class);

            return [
                Attachment::fromData(
                    fn (): string => $service->generate($this->registration),
                    $service->filename($this->registration),
                )->withMime('application/pdf'),
            ];
        } catch (Throwable $e) {
            // A ticket email that arrives without its attachment is recoverable;
            // one that never sends is not.
            report($e);

            return [];
        }
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
