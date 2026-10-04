<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\EventMaterial;
use App\Models\EventRegistration;
use App\Services\Sms\TicketSms;
use Illuminate\Support\Facades\DB;

final class EventRuleTriggerService
{
    /**
     * Called wherever a registration is confirmed, next to the ticket email:
     * runs the organizer's on-registration rules and texts the ticket.
     */
    public function registrationCompleted(EventRegistration $registration): void
    {
        $this->afterCommit(function (AutomatedNotificationDispatcher $dispatcher, EventRegistration $fresh): int {
            app(TicketSms::class)->send($fresh);

            return $dispatcher->dispatchRegistrationRules($fresh);
        }, $registration);
    }

    public function registrationCheckedIn(EventRegistration $registration, string $occurrenceKey): void
    {
        $this->afterCommit(
            fn (AutomatedNotificationDispatcher $dispatcher, EventRegistration $fresh): int => $dispatcher->dispatchCheckInRules($fresh, $occurrenceKey),
            $registration,
        );
    }

    public function materialPublished(EventMaterial $material): void
    {
        if (! $material->isReleased()) {
            return;
        }

        $id = $material->id;
        DB::connection('landlord')->afterCommit(function () use ($id): void {
            $fresh = EventMaterial::query()->find($id);

            if ($fresh instanceof EventMaterial && $fresh->isReleased()) {
                app(AutomatedNotificationDispatcher::class)->dispatchMaterialRules($fresh);
            }
        });
    }

    /** @param  callable(AutomatedNotificationDispatcher, EventRegistration): int  $callback */
    private function afterCommit(callable $callback, EventRegistration $registration): void
    {
        $id = $registration->id;
        DB::connection('landlord')->afterCommit(function () use ($callback, $id): void {
            $fresh = EventRegistration::query()->find($id);

            if ($fresh instanceof EventRegistration) {
                $callback(app(AutomatedNotificationDispatcher::class), $fresh);
            }
        });
    }
}
