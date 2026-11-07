<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventAttendeeProfile;
use App\Models\EventAttendeeProfileConsent;
use App\Models\EventRegistration;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Events\PlatformAttendeeWorkspaceAuthorizer;
use App\Services\Events\Profiles\ProfilePublication;
use App\Services\Events\Profiles\ProfileRollout;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/PublicAttendeeProfileScenario.php';

it('publishes only chosen content with exact consent and no extra ticket side effects', function (): void {
    [$tenant, $event, $registration, $session] = attendeeProfileScenario();
    $before = $registration->fresh()->getAttributes();
    $profile = app(ProfilePublication::class)->publish($registration, $session, attendeeProfileInput());
    expect($profile->state)->toBe('published')->and($profile->display_name)->toBe('Chosen attendee name')
        ->and($profile->display_name)->not->toBe($registration->full_name)
        ->and($profile->adult_confirmed)->toBeTrue()
        ->and($profile->expires_at->equalTo($event->ends_at->addDays(30)))->toBeTrue()
        ->and($registration->fresh()->getAttributes())->toBe($before)
        ->and(EventAttendeeProfileConsent::withoutGlobalScopes()->where('profile_id', $profile->id)->count())->toBe(1);
    $evidence = EventAttendeeProfileConsent::withoutGlobalScopes()->firstOrFail()->getAttributes();
    expect($evidence)->not->toHaveKeys(['email', 'phone', 'display_name', 'registration_id']);
});

it('refuses missing consent adult confirmation unsafe names and malformed operation keys', function (array $changes): void {
    [, , $registration, $session] = attendeeProfileScenario();
    expect(fn () => app(ProfilePublication::class)->publish($registration, $session, attendeeProfileInput($changes)))->toThrow(ValidationException::class);
    expect(EventAttendeeProfile::withoutGlobalScopes()->count())->toBe(0);
})->with([
    [['consent' => false]], [['adult' => false]], [['display_name' => '<b>Someone</b>']],
    [['display_name' => "Line\nBreak"]], [['display_name' => str_repeat('a', 81)]], [['operation_key' => 'bad']],
]);

it('requires independent email proof rather than a checkout or phone grant', function (string $mode): void {
    [, , $registration, $session] = attendeeProfileScenario();
    $session->forget(PlatformAttendeeVerification::SESSION_KEY);
    if ($mode === 'wrong') {
        $session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => 'payer@example.test', 'expires_at' => now()->addHour()->timestamp]);
    } elseif ($mode === 'expired') {
        $session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => $registration->email, 'expires_at' => now()->timestamp]);
    } elseif ($mode === 'checkout') {
        app(PlatformAttendeeWorkspaceAuthorizer::class)->grantCheckoutAccess($session, $registration->id);
    } elseif ($mode === 'phone') {
        $session->put('attendee_verified_phone', ['phone' => $registration->phone, 'expires_at' => now()->addHour()->timestamp]);
    }
    expect(fn () => app(ProfilePublication::class)->publish($registration, $session, attendeeProfileInput()))->toThrow(HttpException::class);
    expect(EventAttendeeProfile::withoutGlobalScopes()->count())->toBe(0);
})->with(['none', 'wrong', 'expired', 'checkout', 'phone']);

it('rechecks stored eligibility instead of trusting a caller model', function (string $change): void {
    [$tenant, $event, $registration, $session] = attendeeProfileScenario();
    if ($change === 'private') {
        Event::withoutGlobalScopes()->whereKey($event->id)->update(['visibility' => Event::VISIBILITY_PRIVATE]);
    } elseif ($change === 'unpublished') {
        Event::withoutGlobalScopes()->whereKey($event->id)->update(['status' => Event::STATUS_DRAFT]);
    } elseif ($change === 'cancelled') {
        EventRegistration::withoutGlobalScopes()->whereKey($registration->id)->update(['status' => EventRegistration::STATUS_CANCELLED]);
    } elseif ($change === 'email') {
        EventRegistration::withoutGlobalScopes()->whereKey($registration->id)->update(['email' => 'other@example.test']);
    } else {
        $tenant->features()->where('feature_key', ProfileRollout::FEATURE)->update(['enabled' => false]);
    }
    expect(fn () => app(ProfilePublication::class)->publish($registration, $session, attendeeProfileInput()))->toThrow(HttpException::class);
    expect(EventAttendeeProfile::withoutGlobalScopes()->count())->toBe(0);
})->with(['private', 'unpublished', 'cancelled', 'email', 'disabled']);

it('replays only an unchanged current publication and refuses a stale retry after withdrawal', function (): void {
    [, , $registration, $session] = attendeeProfileScenario();
    $input = attendeeProfileInput();
    $service = app(ProfilePublication::class);
    $profile = $service->publish($registration, $session, $input);
    $again = $service->publish($registration, $session, $input);
    expect($again->id)->toBe($profile->id)->and($again->revision)->toBe($profile->revision)
        ->and(EventAttendeeProfileConsent::withoutGlobalScopes()->count())->toBe(1);
    $service->withdraw($registration, $session);
    $service->withdraw($registration, $session);
    expect($profile->fresh()->state)->toBe('withdrawn')->and($profile->fresh()->display_name)->toBeNull()
        ->and(EventAttendeeProfileConsent::withoutGlobalScopes()->count())->toBe(2);
    expect(fn () => $service->publish($registration, $session, $input))->toThrow(ValidationException::class);
});

it('refuses same-key content changes and a stale editor revision', function (): void {
    [, , $registration, $session] = attendeeProfileScenario();
    $input = attendeeProfileInput();
    $service = app(ProfilePublication::class);
    $profile = $service->publish($registration, $session, $input);
    expect(fn () => $service->publish($registration, $session, array_replace($input, ['display_name' => 'Different'])))->toThrow(ValidationException::class);
    expect(fn () => $service->publish($registration, $session, attendeeProfileInput(['revision' => (string) Illuminate\Support\Str::uuid()])))->toThrow(ValidationException::class);
    expect($profile->fresh()->display_name)->toBe('Chosen attendee name');
});

it('requires withdrawal before moving a self profile to another ticket sharing the email', function (): void {
    [, $event, $registration, $session] = attendeeProfileScenario();
    $second = EventRegistration::factory()->create(['tenant_id' => $event->tenant_id, 'event_id' => $event->id, 'email' => $registration->email]);
    $service = app(ProfilePublication::class);
    $first = $service->publish($registration, $session, attendeeProfileInput());
    expect(fn () => $service->publish($second, $session, attendeeProfileInput()))->toThrow(ValidationException::class);
    $service->withdraw($registration, $session);
    $next = $service->publish($second, $session, attendeeProfileInput());
    expect($next->id)->toBe($first->id)->and($next->registration_id)->toBe($second->id)
        ->and(EventAttendeeProfile::withoutGlobalScopes()->where('state', 'published')->count())->toBe(1);
});

it('caps open ended recurring events at ninety days without extending a replay', function (): void {
    [, $event, $registration, $session] = attendeeProfileScenario();
    $event->update(['is_recurring' => true, 'ends_at' => now()->addYear()]);
    $this->travelTo(now()->startOfSecond());
    $profile = app(ProfilePublication::class)->publish($registration, $session, attendeeProfileInput());
    expect($profile->expires_at->equalTo(now()->addDays(90)))->toBeTrue();
});

it('permits an owner to withdraw while public profiles or the event are unavailable', function (): void {
    [$tenant, $event, $registration, $session] = attendeeProfileScenario();
    $service = app(ProfilePublication::class);
    $profile = $service->publish($registration, $session, attendeeProfileInput());
    $tenant->features()->where('feature_key', ProfileRollout::FEATURE)->update(['enabled' => false]);
    $event->update(['visibility' => Event::VISIBILITY_PRIVATE]);
    $service->withdraw($registration, $session);
    expect($profile->fresh()->display_name)->toBeNull()->and($profile->fresh()->state)->toBe('withdrawn');
});

it('serves a private empty editor without copying ticket identity into it', function (): void {
    [, , $registration] = attendeeProfileScenario();
    $host = app(App\Services\Tenancy\TenantHostMatcher::class)->baseDomain();
    $this->getJson('http://'.$host.'/my/events/'.$registration->id.'/public-profile', ['HTTP_HOST' => $host])
        ->assertOk()->assertJsonPath('profile.display_name', '')->assertJsonPath('profile.state', 'private')
        ->assertJsonMissingPath('profile.email')->assertJsonMissingPath('profile.registration_id')
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('publishes and withdraws through the native personal ticket routes', function (): void {
    [, , $registration] = attendeeProfileScenario();
    $host = app(App\Services\Tenancy\TenantHostMatcher::class)->baseDomain();
    $url = 'http://'.$host.'/my/events/'.$registration->id.'/public-profile';
    $this->postJson($url, attendeeProfileInput(['owner_hash' => 'forged', 'state' => 'hidden']), ['HTTP_HOST' => $host])
        ->assertOk()->assertJsonPath('profile.state', 'published')->assertJsonPath('profile.display_name', 'Chosen attendee name')
        ->assertJsonMissingPath('profile.owner_hash')->assertHeader('Cache-Control', 'no-store, private');
    $this->deleteJson($url, [], ['HTTP_HOST' => $host])->assertOk()->assertJsonPath('profile.state', 'private')->assertJsonPath('profile.display_name', '');
});

it('does not let a staff authentication or checkout grant change a profile without email proof', function (string $method): void {
    [, , $registration, $session] = attendeeProfileScenario();
    $session->forget(PlatformAttendeeVerification::SESSION_KEY);
    app(PlatformAttendeeWorkspaceAuthorizer::class)->grantCheckoutAccess($session, $registration->id);
    $host = app(App\Services\Tenancy\TenantHostMatcher::class)->baseDomain();
    $url = 'http://'.$host.'/my/events/'.$registration->id.'/public-profile';
    $this->json($method, $url, $method === 'POST' ? attendeeProfileInput() : [], ['HTTP_HOST' => $host])->assertUnauthorized();
})->with(['GET', 'POST', 'DELETE']);

it('refuses a publication at its exact expiry boundary', function (): void {
    [$tenant, , $registration, $session] = attendeeProfileScenario();
    $service = app(ProfilePublication::class);
    $input = attendeeProfileInput();
    $profile = $service->publish($registration, $session, $input);
    $this->travelTo($profile->expires_at);
    $session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => $registration->email, 'expires_at' => now()->addHour()->timestamp]);
    expect(fn () => $service->publish($registration, $session, $input))->toThrow(HttpException::class);
});

it('does not replay a choice after a rollout suspension and restoration', function (): void {
    [$tenant, , $registration, $session] = attendeeProfileScenario();
    $service = app(ProfilePublication::class);
    $input = attendeeProfileInput();
    $profile = $service->publish($registration, $session, $input);
    $row = $tenant->features()->where('feature_key', ProfileRollout::FEATURE)->firstOrFail();
    $row->update(['enabled' => false]);
    $row->update(['enabled' => true]);
    expect(fn () => $service->publish($registration, $session, $input))->toThrow(ValidationException::class);
    expect($service->current($registration, $session)['state'])->toBe('private')
        ->and(EventAttendeeProfileConsent::withoutGlobalScopes()->count())->toBe(1);
});

it('requires fresh consent when the event dates change', function (): void {
    [, $event, $registration, $session] = attendeeProfileScenario();
    $service = app(ProfilePublication::class);
    $profile = $service->publish($registration, $session, attendeeProfileInput());
    $event->update(['ends_at' => $event->ends_at->copy()->addDay()]);
    expect($service->current($registration, $session)['state'])->toBe('private');
});

it('normalises owner identity only within this event and never exposes the proof address', function (): void {
    $policy = app(App\Services\Events\Profiles\ProfileEligibility::class);
    expect($policy->ownerHash('event-a', ' PERSON@example.test '))->toBe($policy->ownerHash('event-a', 'person@example.test'))
        ->and($policy->ownerHash('event-a', 'person@example.test'))->not->toBe($policy->ownerHash('event-b', 'person@example.test'));
});

it('uses the series end rather than the first service date for ongoing gatherings', function (): void {
    [, $event, $registration, $session] = attendeeProfileScenario();
    $event->update(['is_recurring' => true, 'starts_at' => now()->subDays(61), 'ends_at' => now()->subDays(60), 'recurrence_until' => null]);
    $this->travelTo(now()->startOfSecond());
    $profile = app(ProfilePublication::class)->publish($registration, $session, attendeeProfileInput());
    expect($profile->expires_at->equalTo(now()->addDays(90)))->toBeTrue();
});

it('accepts fresh explicit consent immediately after event invalidation without waiting for cleanup', function (): void {
    [, $event, $registration, $session] = attendeeProfileScenario();
    $service = app(ProfilePublication::class);
    $profile = $service->publish($registration, $session, attendeeProfileInput());
    $event->forceFill(['starts_at' => $event->starts_at->addDay(), 'ends_at' => $event->ends_at->addDay()])->save();
    $editor = $service->current($registration, $session);
    expect($editor['state'])->toBe('private');
    $renewed = $service->publish($registration, $session, attendeeProfileInput(['revision' => $editor['revision']]));
    expect($renewed->revision)->not->toBe($profile->revision)->and($service->current($registration, $session)['state'])->toBe('published');
});

it('hides disabled profile reads before advertising email verification to checkout-only holders', function (): void {
    [$tenant, $event, $registration, $session] = attendeeProfileScenario();
    $event->forceFill(['public_attendee_profiles' => false])->save();
    $session->forget(PlatformAttendeeVerification::SESSION_KEY);
    app(PlatformAttendeeWorkspaceAuthorizer::class)->grantCheckoutAccess($session, $registration->id);
    $this->withSession($session->all())->getJson('/my/events/'.$registration->id.'/public-profile')->assertNotFound();
});

it('advertises the optional editor only while the stored event rollout is available', function (): void {
    [, $event, $registration, $session] = attendeeProfileScenario();
    $this->withSession($session->all())->get('/my/events/'.$registration->id)->assertOk()
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page->where('event.public_profiles_available', true));
    $event->forceFill(['public_attendee_profiles' => false])->save();
    $this->get('/my/events/'.$registration->id)->assertOk()
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page->where('event.public_profiles_available', false));
});
