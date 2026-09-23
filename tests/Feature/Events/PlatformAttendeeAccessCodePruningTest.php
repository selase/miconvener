<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\PlatformAttendeeAccessCode;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    refreshTenantDatabases();
});

test('model:prune removes expired unconsumed codes and day-old consumed codes', function (): void {
    $freshCode = PlatformAttendeeAccessCode::factory()->create([
        'email_normalized' => 'fresh@example.com',
        'consumed_at' => null,
        'expires_at' => now()->addMinutes(15),
    ]);

    $recentlyConsumedCode = PlatformAttendeeAccessCode::factory()->create([
        'email_normalized' => 'recent@example.com',
        'consumed_at' => now()->subMinutes(30),
        'expires_at' => now()->addMinutes(10),
    ]);

    $expiredUnconsumedCode = PlatformAttendeeAccessCode::factory()->create([
        'email_normalized' => 'expired@example.com',
        'consumed_at' => null,
        'expires_at' => now()->subMinute(),
    ]);

    $oldConsumedCode = PlatformAttendeeAccessCode::factory()->create([
        'email_normalized' => 'old@example.com',
        'consumed_at' => now()->subHours(25),
        'expires_at' => now()->subHours(24),
    ]);

    Artisan::call('model:prune', [
        '--model' => [PlatformAttendeeAccessCode::class],
    ]);

    expect(PlatformAttendeeAccessCode::query()->find($freshCode->id))->not->toBeNull()
        ->and(PlatformAttendeeAccessCode::query()->find($recentlyConsumedCode->id))->not->toBeNull()
        ->and(PlatformAttendeeAccessCode::query()->find($expiredUnconsumedCode->id))->toBeNull()
        ->and(PlatformAttendeeAccessCode::query()->find($oldConsumedCode->id))->toBeNull();
});
