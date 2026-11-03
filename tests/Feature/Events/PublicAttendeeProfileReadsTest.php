<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventAttendeeProfileConsent;
use App\Models\EventAttendeeProfileImage;
use App\Models\EventRegistration;
use App\Services\Events\Profiles\ProfileImages;
use App\Services\Events\Profiles\ProfilePublication;
use App\Services\Events\Profiles\ProfileRetention;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/PublicAttendeeProfileScenario.php';

it('returns only bounded chosen public fields without ticket or identity data', function (): void {
    [, $event, $registration, $session] = attendeeProfileScenario();
    $profile = app(ProfilePublication::class)->publish($registration, $session, attendeeProfileInput());
    $this->getJson('/attendee-profiles/events/'.$event->id)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonCount(1, 'profiles')->assertJsonPath('profiles.0.display_name', $profile->display_name)
        ->assertJsonMissingPath('profiles.0.registration_id')->assertJsonMissingPath('profiles.0.owner_hash')->assertJsonMissingPath('profiles.0.email');
});

it('suppresses lists immediately after every privacy withdrawal', function (string $change): void {
    [$tenant, $event, $registration, $session] = attendeeProfileScenario();
    $profile = app(ProfilePublication::class)->publish($registration, $session, attendeeProfileInput());
    match ($change) {
        'cancel' => EventRegistration::withoutGlobalScopes()->whereKey($registration->id)->update(['status' => 'cancelled']),
        'transfer' => EventRegistration::withoutGlobalScopes()->whereKey($registration->id)->update(['email' => 'other@example.test']),
        'private' => Event::withoutGlobalScopes()->whereKey($event->id)->update(['visibility' => 'private']),
        'draft' => Event::withoutGlobalScopes()->whereKey($event->id)->update(['status' => 'draft']),
        'switch' => $tenant->features()->where('feature_key', 'public_attendee_profiles')->update(['enabled' => false]),
        'moderated' => $profile->forceFill(['moderated_at' => now(), 'state' => 'hidden'])->save(),
        'expired' => $this->travelTo($profile->expires_at),
        default => app(ProfilePublication::class)->withdraw($registration, $session),
    };
    $response = $this->getJson('/attendee-profiles/events/'.$event->id);
    if (in_array($change, ['private', 'draft', 'switch'], true)) {
        $response->assertNotFound();
    } else {
        $response->assertOk()->assertJsonCount(0, 'profiles');
    }
})->with(['cancel', 'transfer', 'private', 'draft', 'switch', 'moderated', 'expired', 'withdraw']);

it('cleans abandoned private images idempotently and preserves audit until its cutoff', function (): void {
    $this->travel(-91)->days();
    Storage::fake('attendee-profiles-local');
    [, , $registration, $session] = attendeeProfileScenario();
    $file = UploadedFile::fake()->image('photo.png', 60, 60);
    $image = app(ProfileImages::class)->upload($registration, $session, $file);
    $profile = app(ProfilePublication::class)->publish($registration, $session, attendeeProfileInput(['image_id' => $image->id]));
    app(ProfilePublication::class)->withdraw($registration, $session);
    $service = app(ProfileRetention::class);
    $service->sweep();
    $service->sweep();
    Storage::disk($image->disk)->assertMissing($image->path);
    expect($image->fresh()->deleted_at)->not->toBeNull()->and(EventAttendeeProfileConsent::withoutGlobalScopes()->count())->toBe(2);
    $this->travelBack();
    $service->sweep();
    expect(EventAttendeeProfileConsent::withoutGlobalScopes()->count())->toBe(0);
});

it('serves only the current private derivative and rejects old links after withdrawal', function (): void {
    Storage::fake('attendee-profiles-local');
    [, $event, $registration, $session] = attendeeProfileScenario();
    $image = app(ProfileImages::class)->upload($registration, $session, UploadedFile::fake()->image('photo.png', 60, 60));
    $profile = app(ProfilePublication::class)->publish($registration, $session, attendeeProfileInput(['image_id' => $image->id]));
    $url = '/attendee-profiles/'.$profile->id.'/'.$profile->revision.'/photo';
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('X-Content-Type-Options', 'nosniff');
    app(ProfilePublication::class)->withdraw($registration, $session);
    $this->get($url)->assertNotFound();
});

it('refuses a derivative replaced during its storage read', function (): void {
    Storage::fake('attendee-profiles-local');
    [, , $registration, $session] = attendeeProfileScenario();
    $image = app(ProfileImages::class)->upload($registration, $session, UploadedFile::fake()->image('photo.png', 60, 60));
    $service = app(ProfilePublication::class);
    $profile = $service->publish($registration, $session, attendeeProfileInput(['image_id' => $image->id]));
    $bytes = Storage::disk($image->disk)->get($image->path);
    $disk = Mockery::mock(Illuminate\Filesystem\FilesystemAdapter::class);
    $disk->shouldReceive('exists')->andReturn(true);
    $disk->shouldReceive('get')->andReturnUsing(function () use ($service, $registration, $session, $profile, $bytes): string {
        $service->publish($registration, $session, attendeeProfileInput(['revision' => $profile->revision]));

        return $bytes;
    });
    Storage::set($image->disk, $disk);
    $this->get('/attendee-profiles/'.$profile->id.'/'.$profile->revision.'/photo')->assertNotFound();
});

it('retains cleanup metadata after an uncertain deletion and retries the original private object', function (): void {
    $real = Storage::fake('attendee-profiles-local');
    [, , $registration, $session] = attendeeProfileScenario();
    $image = app(ProfileImages::class)->upload($registration, $session, UploadedFile::fake()->image('photo.png', 60, 60));
    $image->forceFill(['retired_at' => now()])->save();
    $failing = Mockery::mock(Illuminate\Filesystem\FilesystemAdapter::class);
    $failing->shouldReceive('exists')->andReturn(true);
    $failing->shouldReceive('delete')->andReturn(false);
    Storage::set($image->disk, $failing);
    app(ProfileRetention::class)->sweep();
    expect($image->fresh()->deleted_at)->toBeNull();
    Storage::set($image->disk, $real);
    app(ProfileRetention::class)->sweep();
    $real->assertMissing($image->path);
    expect($image->fresh()->deleted_at)->not->toBeNull();
});

it('supplies only the enabled directory address to the existing public event page', function (): void {
    [$tenant, $event, $registration, $session] = attendeeProfileScenario();
    app(ProfilePublication::class)->publish($registration, $session, attendeeProfileInput());
    $this->withServerVariables(['HTTP_HOST' => eventSubdomainHost($tenant->slug)])
        ->get(route('public.events.show', ['subdomain' => $tenant->slug, 'event' => $event->slug]))
        ->assertOk()->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page->where('event.public_attendee_profiles_url', '/attendee-profiles/events/'.$event->id)->where('event.going_count', 1)->missing('event.profiles')->missing('event.attendees'));
});

it('cleans a formerly published photograph after its registration is deleted', function (): void {
    Storage::fake('attendee-profiles-local');
    [, , $registration, $session] = attendeeProfileScenario();
    $image = app(ProfileImages::class)->upload($registration, $session, UploadedFile::fake()->image('photo.png', 60, 60));
    app(ProfilePublication::class)->publish($registration, $session, attendeeProfileInput(['image_id' => $image->id]));
    $registration->delete();
    app(ProfileRetention::class)->sweep();
    Storage::disk($image->disk)->assertMissing($image->path);
});

it('does not let successful tombstones block later retired image deletions', function (): void {
    $disk = Storage::fake('attendee-profiles-local');
    [$tenant, $event, $registration, $session] = attendeeProfileScenario();
    $rows = [];
    for ($index = 0; $index < 1001; $index++) {
        $id = (string) Illuminate\Support\Str::uuid();
        $path = 'attendee-profiles/'.$tenant->id.'/'.$id.'.webp';
        $disk->put($path, 'x');
        $rows[] = ['id' => $id, 'tenant_id' => $tenant->id, 'event_id' => $event->id, 'registration_id' => $registration->id,
            'owner_hash' => app(App\Services\Events\Profiles\ProfileEligibility::class)->ownerHash($event->id, $registration->email),
            'disk' => 'attendee-profiles-local', 'path' => $path, 'byte_size' => 1, 'expires_at' => now()->addDay(), 'retired_at' => now(), 'created_at' => now(), 'updated_at' => now()];
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        EventAttendeeProfileImage::withoutGlobalScopes()->insert($chunk);
    }
    app(ProfileRetention::class)->sweep();
    expect(EventAttendeeProfileImage::withoutGlobalScopes()->whereNull('deleted_at')->count())->toBe(1);
    app(ProfileRetention::class)->sweep();
    expect(EventAttendeeProfileImage::withoutGlobalScopes()->whereNull('deleted_at')->count())->toBe(0);
});

it('shortens orphaned consent evidence to ninety days when its registration is deleted', function (): void {
    [, , $registration, $session] = attendeeProfileScenario();
    $profile = app(ProfilePublication::class)->publish($registration, $session, attendeeProfileInput());
    $registration->delete();
    app(ProfileRetention::class)->sweep();
    $evidence = EventAttendeeProfileConsent::withoutGlobalScopes()->where('profile_id', $profile->id)->sole();
    expect($evidence->retain_until->lessThanOrEqualTo(now()->addDays(90)))->toBeTrue();
});
