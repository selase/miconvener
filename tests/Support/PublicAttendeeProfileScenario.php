<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Events\Profiles\ProfileRollout;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/** @return array{Tenant,Event,EventRegistration,Session} */
function attendeeProfileScenario(): array
{
    $tenant = Tenant::factory()->create();
    $tenant->features()->create(['feature_key' => ProfileRollout::FEATURE, 'enabled' => true]);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'public_attendee_profiles' => true, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'email' => 'self@example.test', 'email_verified_at' => now()]);
    $session = app('session.store');
    $session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => $registration->email, 'expires_at' => now()->addHour()->timestamp]);

    return [$tenant, $event, $registration, $session];
}

/** @return array<string,mixed> */
function attendeeProfileInput(array $changes = []): array
{
    return array_replace(['display_name' => 'Chosen attendee name', 'consent' => true, 'adult' => true, 'operation_key' => (string) Str::uuid(), 'revision' => null, 'image_id' => null], $changes);
}
