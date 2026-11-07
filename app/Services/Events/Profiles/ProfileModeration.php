<?php

declare(strict_types=1);

namespace App\Services\Events\Profiles;

use App\Models\EventAttendeeProfile;
use App\Models\EventAttendeeProfileConsent;
use App\Models\EventAttendeeProfileImage;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Throwable;

final class ProfileModeration
{
    public function change(EventAttendeeProfile $candidate, User $actor, string $reason, bool $release = false): void
    {
        $candidate->getConnection()->transaction(function () use ($candidate, $actor, $reason, $release): void {
            Tenant::query()->lock('for no key update')->findOrFail($candidate->tenant_id);
            EventRegistration::withoutGlobalScopes()->lockForUpdate()->findOrFail($candidate->registration_id);
            $profile = EventAttendeeProfile::withoutGlobalScopes()->lockForUpdate()->findOrFail($candidate->id);
            if (($release && $profile->moderated_at === null) || (! $release && $profile->moderated_at !== null)) {
                return;
            }
            $end = CarbonImmutable::now()->min($profile->expires_at ?? now());
            EventAttendeeProfileConsent::withoutGlobalScopes()->where('profile_id', $profile->id)->where('retain_until', '>', $end->addDays(90))->update(['retain_until' => $end->addDays(90)]);
            EventAttendeeProfileConsent::query()->forceCreate(['tenant_id' => $profile->tenant_id, 'event_id' => $profile->event_id,
                'profile_id' => $profile->id, 'subject_hash' => $profile->owner_hash, 'revision' => $profile->revision,
                'action' => $release ? 'release' : 'hide', 'actor_id' => $actor->id, 'reason' => $reason, 'occurred_at' => now(), 'retain_until' => now()->addDays(90)]);
            $profile->forceFill(['state' => $release ? 'private' : 'hidden', 'moderated_at' => $release ? null : now(), 'display_name' => null, 'image_id' => null, 'withdrawn_at' => now()])->save();
            EventAttendeeProfileImage::withoutGlobalScopes()->where('profile_id', $profile->id)->whereNull('deleted_at')->update(['profile_id' => null, 'retired_at' => now()]);
            $profile->getConnection()->afterCommit(function (): void {
                try {
                    \App\Jobs\Events\PurgeAttendeeProfiles::dispatch();
                } catch (Throwable) {
                    \Illuminate\Support\Facades\Log::warning('Moderated attendee photo cleanup will retry on the hourly sweep.');
                }
            });
        }, 3);
    }
}
