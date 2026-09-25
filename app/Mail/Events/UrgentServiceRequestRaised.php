<?php

declare(strict_types=1);

namespace App\Mail\Events;

use App\Mail\Concerns\BrandedForTenant;
use App\Models\Event;
use App\Models\EventServiceRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Reaches someone when an attendee has asked for first aid.
 *
 * The console announces every request to whoever is watching it. This exists
 * for the case where nobody is: an urgent request that waits for a steward to
 * glance at a screen is the one failure worth an inbox.
 */
final class UrgentServiceRequestRaised extends Mailable implements ShouldQueue
{
    use BrandedForTenant;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly EventServiceRequest $serviceRequest) {}

    public function envelope(): Envelope
    {
        $event = $this->serviceRequest->event;

        return $this->brandedEnvelope("Urgent: an attendee needs help at {$event->name}");
    }

    public function content(): Content
    {
        $registration = $this->serviceRequest->registration;
        $seat = $registration?->seatAssignment;

        return new Content(
            markdown: 'emails.events.urgent-service-request',
            with: [
                'event' => $this->serviceRequest->event,
                'serviceRequest' => $this->serviceRequest,
                'attendeeName' => $registration?->full_name,
                'seatLabel' => $seat?->seat_label,
                'roomName' => $seat?->room?->name,
                // Straight to the panel where it can be claimed.
                'requestsUrl' => $this->serviceRequest->tenant->url(
                    '/events/'.$this->serviceRequest->event_id.'/requests'
                ),
            ],
        );
    }

    protected function brandingEvent(): ?Event
    {
        return $this->serviceRequest->event;
    }
}
