<?php

declare(strict_types=1);

namespace App\Mail\Events;

use App\Mail\Concerns\BrandedForTenant;
use App\Models\Event;
use App\Models\EventRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the organizer that somebody is waiting on them.
 *
 * The registrant is told "we'll email you as soon as yours is reviewed" --
 * which only holds if somebody knows there is something to review.
 */
final class EventRegistrationNeedsApproval extends Mailable implements ShouldQueue
{
    use BrandedForTenant;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly EventRegistration $registration) {}

    public function envelope(): Envelope
    {
        return $this->brandedEnvelope("A registration for {$this->registration->event->name} needs your approval");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.events.registration-needs-approval',
            with: [
                'event' => $this->registration->event,
                'registration' => $this->registration,
                // Straight to Guests, where the approve buttons are.
                'reviewUrl' => $this->registration->tenant->url(
                    '/events/'.$this->registration->event->id.'/guests'
                ),
            ],
        );
    }

    protected function brandingEvent(): ?Event
    {
        return $this->registration->event;
    }
}
