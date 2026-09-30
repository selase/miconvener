<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventNotificationLog;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('a tenant can claim one delivery for a channel and dedupe key', function (): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);

    $attributes = [
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'channel' => EventNotificationLog::CHANNEL_EMAIL,
        'status' => EventNotificationLog::STATUS_PENDING,
        'notification_type' => 'rule.on_registration',
        'dedupe_key' => 'registration:rule-1:registration-1:email',
        'attempts' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    expect(DB::connection('landlord')->table('event_notification_logs')->insertOrIgnore($attributes))->toBe(1);

    $attributes['id'] = (string) Str::uuid7();

    expect(DB::connection('landlord')->table('event_notification_logs')->insertOrIgnore($attributes))->toBe(0)
        ->and(EventNotificationLog::withoutGlobalScopes()->count())->toBe(1);
});

test('legacy logs without a dedupe key remain valid and delivery fields are cast', function (): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);

    $first = EventNotificationLog::query()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'channel' => EventNotificationLog::CHANNEL_EMAIL,
        'status' => EventNotificationLog::STATUS_PENDING,
        'notification_type' => 'legacy-compatible',
        'dedupe_key' => null,
        'attempts' => 2,
        'last_attempted_at' => now(),
    ]);

    EventNotificationLog::query()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'channel' => EventNotificationLog::CHANNEL_EMAIL,
        'status' => EventNotificationLog::STATUS_SKIPPED,
        'notification_type' => 'legacy-compatible',
        'dedupe_key' => null,
    ]);

    expect(EventNotificationLog::withoutGlobalScopes()->count())->toBe(2)
        ->and($first->attempts)->toBeInt()->toBe(2)
        ->and($first->last_attempted_at)->toBeInstanceOf(\Carbon\CarbonInterface::class);
});
