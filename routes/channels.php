<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', fn ($user, $id): bool => (int) $user->id === (int) $id);

Broadcast::channel('event.{eventId}.sessions', function ($user, string $eventId): bool {
    $event = App\Models\Event::find($eventId);
    if (! $event) {
        return false;
    }

    return (string) $user->tenant_id === (string) $event->tenant_id;
});

/**
 * Requests name an attendee, where they are sitting, and sometimes that they
 * need first aid, so the channel is open only to staff of the organisation
 * running that event.
 */
Broadcast::channel('event.{eventId}.service-requests', function ($user, string $eventId): bool {
    $event = App\Models\Event::find($eventId);
    if (! $event) {
        return false;
    }

    return (string) $user->tenant_id === (string) $event->tenant_id;
});
