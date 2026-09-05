<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

test('the schedule resets event_registrations and email_credits usage monthly', function () {
    $schedule = app(Schedule::class);
    $descriptions = collect($schedule->events())->map(fn ($event): string => $event->command ?? '')->values();

    expect($descriptions->contains(fn (string $c): bool => str_contains($c, 'tenants:reset-usage') && str_contains($c, '--feature=event_registrations')))->toBeTrue();
    expect($descriptions->contains(fn (string $c): bool => str_contains($c, 'tenants:reset-usage') && str_contains($c, '--feature=email_credits')))->toBeTrue();
});
