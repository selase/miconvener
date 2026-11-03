<?php

declare(strict_types=1);

namespace App\Services\Events\Profiles;

use App\Enum\TenantStatusEnum;
use App\Models\Event;
use App\Models\EventAttendeeProfile;
use App\Models\EventRegistration;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

final readonly class ProfileEligibility
{
    public function __construct(private ProfileRollout $rollout) {}

    public function ownerHash(string $eventId, string $email): string
    {
        return hash_hmac('sha256', 'attendee-profile:'.$eventId.'|'.mb_strtolower(mb_trim($email)), (string) config('app.key'));
    }

    public function registrationEligible(EventRegistration $candidate): bool
    {
        $registration = EventRegistration::withoutGlobalScopes()->find($candidate->id);
        if ($registration === null || ! $registration->isConfirmed() || ! filled($registration->ticket_code) || ! filled($registration->qr_token)) {
            return false;
        }
        $event = Event::withoutGlobalScopes()->find($registration->event_id);
        $tenant = Tenant::query()->find($registration->tenant_id);

        $end = $event === null ? null : $this->publicEnd($event);

        return $event !== null && $event->tenant_id === $registration->tenant_id && $event->deleted_at === null
            && $event->isPublished() && ! $event->isPrivate() && $tenant?->status === TenantStatusEnum::ACTIVE
            && $this->rollout->allows($event) && ($end === null || $end->addDays(30)->isFuture());
    }

    public function publicEnd(Event $event): ?CarbonImmutable
    {
        if ($event->is_recurring) {
            return $event->recurrence_until === null ? null : CarbonImmutable::parse($event->recurrence_until->format('Y-m-d'), $event->timezone)->endOfDay()->utc();
        }

        return CarbonImmutable::instance($event->ends_at);
    }

    /** @phpstan-impure Re-reads current persisted privacy state. */
    public function visible(EventAttendeeProfile $candidate): bool
    {
        $profile = EventAttendeeProfile::withoutGlobalScopes()->where('revision', $candidate->revision)->find($candidate->id);
        if ($profile === null || $profile->state !== 'published' || $profile->moderated_at !== null || $profile->expires_at === null || ! $profile->expires_at->isFuture()) {
            return false;
        }
        $registration = EventRegistration::withoutGlobalScopes()->find($profile->registration_id);
        if ($registration === null || ! $this->registrationEligible($registration)) {
            return false;
        }
        $event = Event::withoutGlobalScopes()->find($profile->event_id);
        $tenant = Tenant::query()->find($profile->tenant_id);

        return $event !== null && $tenant !== null && $registration->tenant_id === $profile->tenant_id
            && $registration->event_id === $profile->event_id
            && hash_equals($this->ownerHash($profile->event_id, $registration->email), $profile->owner_hash)
            && $profile->registration_generation === $registration->attendee_profile_generation
            && $profile->event_generation === $event->attendee_profile_generation
            && $profile->tenant_generation === $tenant->attendee_profile_generation
            && $profile->feature_generation === $this->rollout->generation($tenant);
    }
}
