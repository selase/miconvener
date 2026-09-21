<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

test('the schedule resets event_registrations and email_credits usage monthly', function () {
    $schedule = app(Schedule::class);
    $descriptions = collect($schedule->events())->map(fn ($event): string => $event->command ?? '')->values();

    expect($descriptions->contains(fn (string $c): bool => str_contains($c, 'tenants:reset-usage') && str_contains($c, '--feature=event_registrations')))->toBeTrue();
    expect($descriptions->contains(fn (string $c): bool => str_contains($c, 'tenants:reset-usage') && str_contains($c, '--feature=email_credits')))->toBeTrue();
});

test('the schedule re-syncs tenant features, so a new feature key is not free for everyone', function () {
    $schedule = app(Schedule::class);
    $commands = collect($schedule->events())->map(fn ($event): string => $event->command ?? '')->values();

    // Plan gates treat a missing feature row as permitted, so that a sync which
    // has not run cannot strip a paying tenant of a capability. The cost is
    // that a newly added key is granted to everyone until the rows exist, and
    // this is what closes that window without anyone having to remember.
    expect($commands->contains(fn (string $c): bool => str_contains($c, 'tenants:sync-features')))->toBeTrue();
});

test('the schedule dispatches automated event notifications, so organiser reminders actually fire', function () {
    $schedule = app(Schedule::class);
    $commands = collect($schedule->events())->map(fn ($event): string => $event->command ?? '')->values();

    // Organisers build scheduled reminder rules in the event console. Without a
    // scheduler entry the command only ever runs when a superadmin triggers it
    // by hand, so every reminder a tenant configures silently never sends.
    expect($commands->contains(fn (string $c): bool => str_contains($c, 'app:dispatch-automated-notifications')))->toBeTrue();
});
