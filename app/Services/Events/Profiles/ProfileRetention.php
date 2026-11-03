<?php

declare(strict_types=1);

namespace App\Services\Events\Profiles;

use App\Models\EventAttendeeProfile;
use App\Models\EventAttendeeProfileConsent;
use App\Models\EventAttendeeProfileImage;
use App\Models\EventRegistration;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final readonly class ProfileRetention
{
    public function __construct(private ProfileEligibility $eligibility) {}

    public function sweep(): void
    {
        $after = Cache::get('attendee_profile_retention_cursor');
        $batch = EventAttendeeProfile::withoutGlobalScopes()->where('state', 'published')->when(is_string($after), fn ($query) => $query->where('id', '>', $after))->orderBy('id')->limit(1000)->get();
        Cache::put('attendee_profile_retention_cursor', $batch->count() === 1000 ? $batch->last()?->id : null, 86400);
        foreach ($batch as $candidate) {
            if ($this->eligibility->visible($candidate)) {
                continue;
            }
            $candidate->getConnection()->transaction(function () use ($candidate): void {
                Tenant::query()->lock('for no key update')->find($candidate->tenant_id);
                EventRegistration::withoutGlobalScopes()->lockForUpdate()->find($candidate->registration_id);
                $profile = EventAttendeeProfile::withoutGlobalScopes()->lockForUpdate()->find($candidate->id);
                if ($profile === null || $profile->state !== 'published' || $this->eligibility->visible($profile)) {
                    return;
                }
                $end = CarbonImmutable::now()->min($profile->expires_at ?? now());
                EventAttendeeProfileConsent::withoutGlobalScopes()->where('profile_id', $profile->id)->where('revision', $profile->revision)
                    ->where('retain_until', '>', $end->addDays(90))->update(['retain_until' => $end->addDays(90)]);
                EventAttendeeProfileConsent::query()->forceCreate(['tenant_id' => $profile->tenant_id, 'event_id' => $profile->event_id,
                    'profile_id' => $profile->id, 'subject_hash' => $profile->owner_hash, 'revision' => $profile->revision,
                    'action' => 'expire', 'consent_version' => $profile->consent_version, 'occurred_at' => now(), 'retain_until' => $end->addDays(90)]);
                $profile->forceFill(['state' => 'expired', 'display_name' => null, 'image_id' => null, 'withdrawn_at' => now()])->save();
                EventAttendeeProfileImage::withoutGlobalScopes()->where('profile_id', $profile->id)->whereNull('deleted_at')->update(['profile_id' => null, 'retired_at' => now()]);
            }, 3);
        }
        foreach (EventAttendeeProfileImage::withoutGlobalScopes()->where(function ($query): void {
            $query->whereNotNull('retired_at')->orWhere('expires_at', '<=', now());
        })->where(function ($query): void {
            $query->whereNull('deleted_at')->orWhere('deleted_at', '>', now()->subDay());
        })->orderBy('id')->limit(1000)->get() as $candidate) {
            try {
                $candidate->getConnection()->transaction(function () use ($candidate): void {
                    $image = EventAttendeeProfileImage::withoutGlobalScopes()->lockForUpdate()->find($candidate->id);
                    if ($image === null || ($image->retired_at === null && $image->expires_at->isFuture())) {
                        return;
                    }
                    if (! in_array($image->disk, ['s3', 'attendee-profiles-local'], true) || ! str_starts_with($image->path, 'attendee-profiles/'.$image->tenant_id.'/')) {
                        throw new RuntimeException('Unexpected private profile namespace.');
                    }
                    $disk = Storage::disk($image->disk);
                    if ($disk->exists($image->path) && (! $disk->delete($image->path) || ! $disk->missing($image->path))) {
                        throw new RuntimeException('Private profile image deletion is unconfirmed.');
                    }
                    $image->forceFill(['retired_at' => $image->retired_at ?? now(), 'deleted_at' => $image->deleted_at ?? now(), 'profile_id' => null])->save();
                });
            } catch (Throwable) {
                Log::warning('Private attendee photo cleanup needs retry.', ['image_id' => $candidate->id, 'overdue' => ($candidate->retired_at ?? $candidate->expires_at)->lessThanOrEqualTo(now()->subDay())]);
            }
        }
        EventAttendeeProfileConsent::withoutGlobalScopes()->where('retain_until', '<=', now())->limit(1000)->get()->each->delete();
        EventAttendeeProfileImage::withoutGlobalScopes()->where('deleted_at', '<=', now()->subDay())->limit(1000)->get()->each->delete();
        EventAttendeeProfile::withoutGlobalScopes()->whereIn('state', ['withdrawn', 'expired', 'private'])->whereNotNull('withdrawn_at')->where('withdrawn_at', '<=', now()->subDays(90))->whereNull('moderated_at')->whereNotExists(fn ($query) => $query->selectRaw('1')->from('event_attendee_profile_consents')->whereColumn('event_attendee_profile_consents.profile_id', 'event_attendee_profiles.id'))->limit(1000)->get()->each->delete();
    }
}
