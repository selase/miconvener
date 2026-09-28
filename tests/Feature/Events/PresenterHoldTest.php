<?php

declare(strict_types=1);

use App\Services\Events\DeckPresenter;
use App\Services\Events\PlatformAttendeeVerification;

test('a presenter is not signed out mid-deck', function (): void {
    [$tenant, $event, $deck] = presenterScenario('presenter-hold');
    $event->update(['ends_at' => now()->addHours(20)]);

    app(DeckPresenter::class)->start($deck);

    $session = app('session.store');
    $session->put(PlatformAttendeeVerification::SESSION_KEY, [
        'email' => 'speaker@example.com',
        'verified_at' => now()->getTimestamp(),
        'expires_at' => now()->addHours(12)->getTimestamp(),
    ]);

    // Twelve hours is enough for an event day and is deliberately fixed. It
    // must not expire in front of a room.
    $this->travel(13)->hours();

    expect(app(PlatformAttendeeVerification::class)->verifiedEmail($session, $deck->fresh()))
        ->toBe('speaker@example.com');
});

test('the hold ends when the deck does', function (): void {
    [$tenant, $event, $deck] = presenterScenario('presenter-hold-ends');

    app(DeckPresenter::class)->start($deck);
    app(DeckPresenter::class)->end($deck->fresh());

    $session = app('session.store');
    $session->put(PlatformAttendeeVerification::SESSION_KEY, [
        'email' => 'speaker@example.com',
        'verified_at' => now()->getTimestamp(),
        'expires_at' => now()->addHours(12)->getTimestamp(),
    ]);

    $this->travel(13)->hours();

    expect(app(PlatformAttendeeVerification::class)->verifiedEmail($session, $deck->fresh()))
        ->toBeNull();
});

test('the hold does not extend anyone who is not presenting', function (): void {
    [$tenant, $event, $deck] = presenterScenario('presenter-hold-others');

    app(DeckPresenter::class)->start($deck);

    $session = app('session.store');
    $session->put(PlatformAttendeeVerification::SESSION_KEY, [
        'email' => 'attendee@example.com',
        'verified_at' => now()->getTimestamp(),
        'expires_at' => now()->addHours(12)->getTimestamp(),
    ]);

    $this->travel(13)->hours();

    // A live deck in the building is not a reason to keep everyone signed in.
    // Only a caller that names the deck it is presenting gets the hold.
    expect(app(PlatformAttendeeVerification::class)->verifiedEmail($session))
        ->toBeNull();
});
