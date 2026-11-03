<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventAttendeeProfile;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Services\Events\Profiles\ProfileRollout;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('adds a private profile store without enabling an existing event', function (): void {
    expect(Schema::connection('landlord')->hasTable('event_attendee_profiles'))->toBeTrue();
    $event = Event::factory()->published()->create();
    expect($event->public_attendee_profiles)->toBeFalse()
        ->and(app(ProfileRollout::class)->allows($event))->toBeFalse();
});

it('requires both explicit tenant and event switches', function (): void {
    $tenant = Tenant::factory()->create();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'public_attendee_profiles' => true]);
    $rollout = app(ProfileRollout::class);
    expect($rollout->allows($event))->toBeFalse();
    $tenant->features()->create(['feature_key' => ProfileRollout::FEATURE, 'enabled' => true]);
    expect($rollout->allows($event))->toBeTrue();
    $event->forceFill(['public_attendee_profiles' => false])->save();
    expect($rollout->allows($event->fresh()))->toBeFalse();
});

it('invalidates privacy generations even for bulk event and registration writers', function (): void {
    $tenant = Tenant::factory()->create();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    $eventGeneration = $event->fresh()->attendee_profile_generation;
    $registrationGeneration = $registration->fresh()->attendee_profile_generation;
    expect($eventGeneration)->not->toBeNull()->and($registrationGeneration)->not->toBeNull();
    Event::withoutGlobalScopes()->whereKey($event->id)->update(['visibility' => Event::VISIBILITY_PRIVATE]);
    Event::withoutGlobalScopes()->whereKey($event->id)->update(['visibility' => Event::VISIBILITY_PUBLIC]);
    EventRegistration::withoutGlobalScopes()->whereKey($registration->id)->update(['email' => 'new@example.test']);
    EventRegistration::withoutGlobalScopes()->whereKey($registration->id)->update(['email' => $registration->email]);
    expect($event->fresh()->attendee_profile_generation)->not->toBe($eventGeneration)
        ->and($registration->fresh()->attendee_profile_generation)->not->toBe($registrationGeneration);
});

it('keeps a feature generation revoked after disable and reenable in the same second', function (): void {
    $tenant = Tenant::factory()->create();
    $feature = $tenant->features()->create(['feature_key' => ProfileRollout::FEATURE, 'enabled' => true]);
    $original = $feature->fresh()->meta['profile_generation'];
    $feature->update(['enabled' => false]);
    $feature->update(['enabled' => true, 'meta' => []]);
    expect($feature->fresh()->meta['profile_generation'])->not->toBe($original);
});

it('enforces the complete registration context in the database', function (): void {
    $registration = EventRegistration::factory()->create();
    expect(fn () => EventAttendeeProfile::query()->forceCreate([
        'id' => Str::uuid(), 'tenant_id' => $registration->tenant_id, 'event_id' => $registration->event_id,
        'registration_id' => $registration->id, 'owner_hash' => str_repeat('a', 64),
    ]))->toThrow(QueryException::class);
});

it('refuses duplicate self profiles for one email and event', function (): void {
    $tenant = Tenant::factory()->create();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $first = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    $second = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    EventAttendeeProfile::factory()->forRegistration($first)->create(['owner_hash' => str_repeat('a', 64)]);
    expect(fn () => EventAttendeeProfile::factory()->forRegistration($second)->create(['owner_hash' => str_repeat('a', 64)]))->toThrow(QueryException::class);
});

it('does not mass assign or serialize identity storage and consent fields', function (): void {
    $profile = new EventAttendeeProfile;
    expect($profile->isFillable('owner_hash'))->toBeFalse();
    $profile->forceFill(['id' => (string) Str::uuid(), 'owner_hash' => 'private', 'registration_id' => 'private', 'display_name' => 'Not yet consented']);
    expect($profile->toArray())->toBe(['id' => $profile->id]);
});

it('keeps an inactive organisation closed even when both switches are enabled', function (): void {
    $tenant = Tenant::factory()->create(['status' => App\Enum\TenantStatusEnum::BANNED]);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'public_attendee_profiles' => true]);
    $tenant->features()->create(['feature_key' => ProfileRollout::FEATURE, 'enabled' => true]);
    expect(app(ProfileRollout::class)->allows($event))->toBeFalse();
});

it('does not permit a published database row without its consent evidence fields', function (): void {
    expect(fn () => EventAttendeeProfile::factory()->create(['state' => 'published']))->toThrow(QueryException::class);
});

it('keeps consent facts immutable but permits shortening their retention deadline', function (): void {
    $consent = App\Models\EventAttendeeProfileConsent::factory()->create();
    $consent->forceFill(['retain_until' => now()->addDays(89)])->save();
    expect(fn () => $consent->forceFill(['action' => 'publish'])->save())->toThrow(QueryException::class);
});

it('enables only the named organisation and never enables its event automatically', function (): void {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $this->artisan('events:enable-attendee-profiles '.$tenant->slug)->assertSuccessful();
    expect(app(ProfileRollout::class)->generation($tenant))->not->toBeNull()
        ->and(app(ProfileRollout::class)->generation($other))->toBeNull()
        ->and($event->fresh()->public_attendee_profiles)->toBeFalse();
    $this->artisan('events:enable-attendee-profiles '.$tenant->slug.' --off')->assertSuccessful();
    expect(app(ProfileRollout::class)->generation($tenant))->toBeNull();
});

it('does not resurrect a tenant privacy generation after suspension and restoration', function (): void {
    $tenant = Tenant::factory()->create();
    $generation = $tenant->fresh()->attendee_profile_generation;
    expect($generation)->not->toBeNull();
    Tenant::query()->whereKey($tenant->id)->update(['status' => App\Enum\TenantStatusEnum::BANNED]);
    Tenant::query()->whereKey($tenant->id)->update(['status' => App\Enum\TenantStatusEnum::ACTIVE]);
    expect($tenant->fresh()->attendee_profile_generation)->not->toBe($generation);
});

it('rejects incomplete or empty publication deadlines in the database', function (?string $deadline): void {
    expect(fn () => EventAttendeeProfile::factory()->create([
        'state' => 'published', 'display_name' => 'Sample attendee', 'adult_confirmed' => true,
        'revision' => Str::uuid(), 'tenant_generation' => Str::uuid(), 'feature_generation' => Str::uuid(),
        'event_generation' => Str::uuid(), 'registration_generation' => Str::uuid(),
        'consent_version' => 'test-v1', 'consented_at' => '2026-11-03T12:00:00Z', 'expires_at' => $deadline,
    ]))->toThrow(QueryException::class);
})->with([null, '2026-11-03T12:00:00Z', '2027-05-01T12:00:00Z']);

it('rejects a photo attached to a profile from another organisation', function (): void {
    expect(fn () => App\Models\EventAttendeeProfileImage::factory()->create(['tenant_id' => Tenant::factory()->create()->id]))->toThrow(QueryException::class);
});

it('does not delete consent evidence before its retention cutoff', function (): void {
    $consent = App\Models\EventAttendeeProfileConsent::factory()->create();
    expect(fn () => $consent->delete())->toThrow(QueryException::class);
});

it('resolves only the profile own registration event and organisation', function (): void {
    $profile = EventAttendeeProfile::factory()->create();
    expect($profile->registration?->id)->toBe($profile->registration_id)
        ->and($profile->event?->id)->toBe($profile->event_id)
        ->and($profile->tenant?->id)->toBe($profile->tenant_id);
});

it('does not trust a forged or stale event switch in a caller model', function (): void {
    $tenant = Tenant::factory()->create();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $tenant->features()->create(['feature_key' => ProfileRollout::FEATURE, 'enabled' => true]);
    $event->public_attendee_profiles = true;
    expect(app(ProfileRollout::class)->allows($event))->toBeFalse();
});

it('refuses manually clearing a consent actor while their account still exists', function (): void {
    $actor = App\Models\User::factory()->create();
    $consent = App\Models\EventAttendeeProfileConsent::factory()->create(['actor_id' => $actor->id]);
    expect(fn () => $consent->forceFill(['actor_id' => null])->save())->toThrow(QueryException::class);
});

it('allows a deleted staff account to clear its retained consent attribution', function (): void {
    $actor = App\Models\User::factory()->create();
    $consent = App\Models\EventAttendeeProfileConsent::factory()->create(['actor_id' => $actor->id]);
    $actor->delete();
    expect($consent->fresh()->actor_id)->toBeNull();
});
