<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EventAttendeeProfile;
use App\Models\EventAttendeeProfileConsent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventAttendeeProfileConsent> */
final class EventAttendeeProfileConsentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'profile_id' => EventAttendeeProfile::factory(),
            'tenant_id' => fn (array $a) => EventAttendeeProfile::withoutGlobalScopes()->findOrFail($a['profile_id'])->tenant_id,
            'event_id' => fn (array $a) => EventAttendeeProfile::withoutGlobalScopes()->findOrFail($a['profile_id'])->event_id,
            'subject_hash' => fn (array $a) => EventAttendeeProfile::withoutGlobalScopes()->findOrFail($a['profile_id'])->owner_hash,
            'action' => 'withdraw',
            'revision' => fake()->uuid(),
            'occurred_at' => now(),
            'retain_until' => now()->addDays(90),
        ];
    }
}
