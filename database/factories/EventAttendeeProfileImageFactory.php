<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EventAttendeeProfile;
use App\Models\EventAttendeeProfileImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventAttendeeProfileImage> */
final class EventAttendeeProfileImageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'profile_id' => EventAttendeeProfile::factory(),
            'tenant_id' => fn (array $a) => EventAttendeeProfile::withoutGlobalScopes()->findOrFail($a['profile_id'])->tenant_id,
            'event_id' => fn (array $a) => EventAttendeeProfile::withoutGlobalScopes()->findOrFail($a['profile_id'])->event_id,
            'registration_id' => fn (array $a) => EventAttendeeProfile::withoutGlobalScopes()->findOrFail($a['profile_id'])->registration_id,
            'owner_hash' => fn (array $a) => EventAttendeeProfile::withoutGlobalScopes()->findOrFail($a['profile_id'])->owner_hash,
            'disk' => 'attendee-profiles-local',
            'path' => 'attendee-profiles/'.fake()->uuid().'.webp',
            'byte_size' => 100,
            'expires_at' => now()->addMinutes(15),
        ];
    }
}
