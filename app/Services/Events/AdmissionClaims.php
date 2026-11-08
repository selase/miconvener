<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventRegistration;

/** Conservative reservations on mixed-registration events; no automatic claim expiry. */
final class AdmissionClaims
{
    public function applies(Event $event): bool
    {
        return $event->registrations()->where('registration_source', 'external_import')->exists();
    }

    public function count(Event $event, ?string $ticketTypeId = null, ?string $exceptRegistrationId = null): int
    {
        return $event->registrations()->where(function ($query): void {
            $query->whereNotIn('status', [EventRegistration::STATUS_CANCELLED, EventRegistration::STATUS_REJECTED, EventRegistration::STATUS_WAITLISTED])
                ->orWhere(function ($query): void {
                    $query->where('registration_source', 'native')->where('amount', '>', 0)->where(function ($query): void {
                        $query->whereIn('status', [EventRegistration::STATUS_CANCELLED, EventRegistration::STATUS_REJECTED])
                            ->orWhere(fn ($query) => $query->where('status', EventRegistration::STATUS_WAITLISTED)->where(fn ($query) => $query->whereNull('checkout_admission_guard_version')->orWhere('checkout_admission_guard_version', '!=', 1)));
                    });
                });
        })
            ->when($ticketTypeId !== null, fn ($query) => $query->where('ticket_type_id', $ticketTypeId))
            ->when($exceptRegistrationId !== null, fn ($query) => $query->where('id', '!=', $exceptRegistrationId))->count();
    }

    public function isFull(Event $event, ?\App\Models\EventTicketType $ticket = null): bool
    {
        $capacity = $ticket === null ? $event->capacity : $ticket->capacity;
        if ($capacity === null) {
            return false;
        }
        $count = $this->applies($event) ? $this->count($event, $ticket?->id) : ($ticket === null ? $event->registrations()->confirmed()->count() : $ticket->confirmedCount());

        return $count >= $capacity;
    }

    /** Reread current state in the existing-registration lock order, before provider HTTP. */
    public function forCheckout(EventRegistration $candidate): EventRegistration
    {
        return $candidate->getConnection()->transaction(function () use ($candidate): EventRegistration {
            $registration = EventRegistration::withoutGlobalScopes()->where('tenant_id', $candidate->tenant_id)->lockForUpdate()->findOrFail($candidate->id);
            $event = Event::withoutGlobalScopes()->where('tenant_id', $registration->tenant_id)->lockForUpdate()->findOrFail($registration->event_id);
            abort_unless($event->isPublished() && ! $event->trashed() && $registration->status === EventRegistration::STATUS_PENDING_PAYMENT && ! $registration->isImported(), 422, 'Only an approved pending payment can start checkout. Open the current registration status instead.');
            $registration->setRelation('event', $event);

            return $registration;
        });
    }
}
