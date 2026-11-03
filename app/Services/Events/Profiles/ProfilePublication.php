<?php

declare(strict_types=1);

namespace App\Services\Events\Profiles;

use App\Http\Requests\Attendee\PublishPublicAttendeeProfileRequest;
use App\Models\Event;
use App\Models\EventAttendeeProfile;
use App\Models\EventAttendeeProfileConsent;
use App\Models\EventAttendeeProfileImage;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Services\Events\PlatformAttendeeVerification;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class ProfilePublication
{
    public const string CONSENT_VERSION = '2026-11-03-v1';

    public function __construct(private PlatformAttendeeVerification $verification, private ProfileRollout $rollout, private ProfileEligibility $eligibility) {}

    /** @param array<string,mixed> $input */
    public function publish(EventRegistration $candidate, Session $session, array $input): EventAttendeeProfile
    {
        $registration = $this->ownedRegistration($candidate, $session);
        $request = new PublishPublicAttendeeProfileRequest;
        /** @var array{display_name:string,image_id?:?string,operation_key:string,revision?:?string} $data */
        $data = Validator::make($input, $request->rules(), $request->messages())->validate();
        $data['display_name'] = mb_trim($data['display_name']);
        if ($data['display_name'] === '') {
            throw ValidationException::withMessages(['display_name' => 'Choose a public display name.']);
        }

        return $registration->getConnection()->transaction(function () use ($registration, $session, $data): EventAttendeeProfile {
            $tenant = Tenant::query()->lock('for no key update')->findOrFail($registration->tenant_id);
            $current = EventRegistration::withoutGlobalScopes()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($registration->id);
            $this->ownedRegistration($current, $session);
            abort_unless($this->eligibility->registrationEligible($current), 403, 'This ticket is not eligible for a public profile.');
            $event = Event::withoutGlobalScopes()->where('tenant_id', $tenant->id)->findOrFail($current->event_id);
            foreach ([$tenant, $current, $event] as $model) {
                if ($model->attendee_profile_generation === null) {
                    $model->forceFill(['attendee_profile_generation' => (string) Str::uuid()])->saveQuietly();
                    $model->refresh();
                }
            }
            $hash = $this->eligibility->ownerHash($event->id, $current->email);
            $owned = EventAttendeeProfile::withoutGlobalScopes()->where('event_id', $event->id)->where('owner_hash', $hash)->lockForUpdate()->first();
            $atRegistration = EventAttendeeProfile::withoutGlobalScopes()->where('registration_id', $current->id)->lockForUpdate()->first();
            if ($owned !== null && $owned->registration_id !== $current->id && $owned->state === 'published' && $this->eligibility->visible($owned)) {
                throw ValidationException::withMessages(['profile' => 'Remove your public profile from the other ticket before choosing this one.']);
            }
            if ($atRegistration !== null && $atRegistration->owner_hash !== $hash) {
                $this->closeRevision($atRegistration, 'withdraw');
                $this->retireImages($atRegistration);
                $atRegistration->delete();
                $atRegistration = null;
            }
            $profile = $owned ?? $atRegistration;
            if ($profile?->moderated_at !== null) {
                abort(403, 'This profile is hidden for review. Contact the organiser.');
            }
            $inputHash = hash('sha256', json_encode([$data['display_name'], $data['image_id'] ?? null, self::CONSENT_VERSION], JSON_THROW_ON_ERROR));
            if ($profile !== null && $profile->operation_key === $data['operation_key']) {
                if ($profile->input_hash === $inputHash && $this->eligibility->visible($profile)) {
                    return $profile;
                }
                throw ValidationException::withMessages(['profile' => 'This choice has changed. Reload and choose again before publishing.']);
            }
            if ($profile?->state === 'published' && $profile->registration_id === $current->id && ($data['revision'] ?? null) !== $profile->revision) {
                throw ValidationException::withMessages(['revision' => 'This profile changed in another tab. Reload before publishing.']);
            }
            $image = $this->image($data['image_id'] ?? null, $current, $hash, $profile);
            $now = CarbonImmutable::now()->startOfSecond();
            $expires = $now->addDays(90);
            $publicEnd = $this->eligibility->publicEnd($event);
            if ($publicEnd !== null) {
                $expires = $expires->min($publicEnd->addDays(30));
            }
            if ($profile !== null) {
                $this->closeRevision($profile, 'replace');
                $this->retireImages($profile, $image?->id);
            }
            $profile ??= new EventAttendeeProfile;
            $profile->forceFill([
                'tenant_id' => $tenant->id, 'event_id' => $event->id, 'registration_id' => $current->id,
                'owner_hash' => $hash, 'display_name' => $data['display_name'], 'adult_confirmed' => true,
                'state' => 'published', 'revision' => (string) Str::uuid(), 'image_id' => $image?->id,
                'feature_generation' => $this->rollout->generation($tenant), 'tenant_generation' => $tenant->attendee_profile_generation,
                'event_generation' => $event->attendee_profile_generation, 'registration_generation' => $current->attendee_profile_generation,
                'operation_key' => $data['operation_key'], 'input_hash' => $inputHash, 'consent_version' => self::CONSENT_VERSION,
                'consented_at' => $now, 'expires_at' => $expires, 'withdrawn_at' => null,
            ])->save();
            if ($image !== null) {
                $image->forceFill(['profile_id' => $profile->id, 'expires_at' => $expires])->save();
            }
            $this->evidence($profile, 'publish', $expires->addDays(90));

            return $profile->refresh();
        }, 3);
    }

    public function withdraw(EventRegistration $candidate, Session $session): void
    {
        $registration = $this->ownedRegistration($candidate, $session);
        $registration->getConnection()->transaction(function () use ($registration, $session): void {
            Tenant::query()->lock('for no key update')->findOrFail($registration->tenant_id);
            $current = EventRegistration::withoutGlobalScopes()->where('tenant_id', $registration->tenant_id)->lockForUpdate()->findOrFail($registration->id);
            $this->ownedRegistration($current, $session);
            $profile = EventAttendeeProfile::withoutGlobalScopes()->where('registration_id', $current->id)
                ->where('owner_hash', $this->eligibility->ownerHash($current->event_id, $current->email))->lockForUpdate()->first();
            if ($profile === null || in_array($profile->state, ['private', 'withdrawn', 'expired'], true)) {
                return;
            }
            $this->closeRevision($profile, 'withdraw');
            $this->retireImages($profile);
            $profile->forceFill(['state' => $profile->moderated_at === null ? 'withdrawn' : 'hidden', 'display_name' => null, 'image_id' => null, 'withdrawn_at' => now()])->save();
        }, 3);
    }

    public function ownedRegistration(EventRegistration $candidate, Session $session): EventRegistration
    {
        $current = EventRegistration::withoutGlobalScopes()->findOrFail($candidate->id);
        $email = $this->verification->verifiedEmail($session);
        abort_unless($email !== null && PlatformAttendeeVerification::normalise($current->email) === PlatformAttendeeVerification::normalise($email), 401, 'Verify the email on your own ticket before changing its public profile.');

        return $current;
    }

    /** @return array<string,mixed> */
    public function current(EventRegistration $candidate, Session $session): array
    {
        $registration = $this->ownedRegistration($candidate, $session);
        $profile = EventAttendeeProfile::withoutGlobalScopes()->where('registration_id', $registration->id)
            ->where('owner_hash', $this->eligibility->ownerHash($registration->event_id, $registration->email))->first();
        $visible = $profile !== null && $this->eligibility->visible($profile);

        return [
            'eligible' => $this->eligibility->registrationEligible($registration),
            'state' => $profile?->moderated_at !== null ? 'hidden' : ($visible ? 'published' : 'private'),
            'revision' => $visible ? $profile->revision : null,
            'display_name' => $visible ? $profile->display_name : '',
            'expires_at' => $visible ? $profile->expires_at->toIso8601String() : null,
            'image' => $visible && $profile->image_id !== null ? ['id' => $profile->image_id, 'url' => '/attendee-profiles/'.$profile->id.'/'.$profile->revision.'/photo'] : null,
        ];
    }

    private function image(?string $id, EventRegistration $registration, string $hash, ?EventAttendeeProfile $profile): ?EventAttendeeProfileImage
    {
        if ($id === null) {
            return null;
        }
        $image = EventAttendeeProfileImage::withoutGlobalScopes()->whereKey($id)->where('tenant_id', $registration->tenant_id)
            ->where('event_id', $registration->event_id)->where('registration_id', $registration->id)->where('owner_hash', $hash)->lockForUpdate()->first();
        if ($image === null || $image->retired_at !== null || $image->deleted_at !== null || ! $image->expires_at->isFuture()
            || ($image->profile_id !== null && $image->profile_id !== $profile?->id)) {
            throw ValidationException::withMessages(['image_id' => 'Choose a fresh photo uploaded for this ticket.']);
        }

        return $image;
    }

    private function retireImages(EventAttendeeProfile $profile, ?string $except = null): void
    {
        EventAttendeeProfileImage::withoutGlobalScopes()->where('profile_id', $profile->id)->whereNull('deleted_at')
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->update(['profile_id' => null, 'retired_at' => now()]);
    }

    private function closeRevision(EventAttendeeProfile $profile, string $action): void
    {
        if ($profile->revision === null || $profile->withdrawn_at !== null) {
            return;
        }
        $end = CarbonImmutable::now()->min($profile->expires_at ?? now());
        EventAttendeeProfileConsent::withoutGlobalScopes()->where('profile_id', $profile->id)->where('revision', $profile->revision)
            ->where('retain_until', '>', $end->addDays(90))->update(['retain_until' => $end->addDays(90)]);
        $this->evidence($profile, $action, $end->addDays(90));
    }

    private function evidence(EventAttendeeProfile $profile, string $action, CarbonImmutable $retainUntil): void
    {
        EventAttendeeProfileConsent::query()->forceCreate([
            'tenant_id' => $profile->tenant_id, 'event_id' => $profile->event_id, 'profile_id' => $profile->id,
            'subject_hash' => $profile->owner_hash, 'revision' => $profile->revision, 'action' => $action,
            'consent_version' => $profile->consent_version, 'occurred_at' => now(), 'retain_until' => $retainUntil,
        ]);
    }
}
