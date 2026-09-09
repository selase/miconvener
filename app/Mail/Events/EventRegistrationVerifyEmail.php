<?php

declare(strict_types=1);

namespace App\Mail\Events;

use App\Models\EventRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

final class EventRegistrationVerifyEmail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public EventRegistration $registration) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Confirm your email for {$this->registration->event->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.events.registration-verify-email',
            with: [
                'event' => $this->registration->event,
                'registration' => $this->registration,
                // Signed rather than a stored token: the link cannot be forged
                // and expires on its own, with nothing to clean up afterwards.
                'verifyUrl' => URL::temporarySignedRoute(
                    'public.events.registrations.verify',
                    now()->addDays(7),
                    [
                        'subdomain' => $this->registration->tenant->slug,
                        'event' => $this->registration->event->slug,
                        'registration' => $this->registration->id,
                    ],
                ),
            ],
        );
    }
}
