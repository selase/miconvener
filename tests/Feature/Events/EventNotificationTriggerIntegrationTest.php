<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventMaterial;
use App\Models\EventNotificationLog;
use App\Models\EventNotificationRule;
use App\Models\EventRegistration;
use App\Services\Notifications\EventRuleTriggerService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Mail::fake();
});

function createOccurrenceRule(Event $event, string $trigger): EventNotificationRule
{
    return EventNotificationRule::create([
        'tenant_id' => $event->tenant_id,
        'event_id' => $event->id,
        'name' => $trigger,
        'target_role' => EventNotificationRule::ROLE_ATTENDEE,
        'target_audience' => 'all',
        'trigger_type' => $trigger,
        'channels' => ['email'],
        'subject' => 'Update',
        'body_template' => 'Hello {name}.',
        'is_active' => true,
    ]);
}

test('registration and check-in triggers are deduplicated by their durable occurrence', function (): void {
    [$tenant] = eventHost('trigger-integration');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    createOccurrenceRule($event, EventNotificationRule::TRIGGER_ON_REGISTRATION);
    createOccurrenceRule($event, EventNotificationRule::TRIGGER_ON_CHECKIN);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);
    $triggers = app(EventRuleTriggerService::class);

    $triggers->registrationCompleted($registration);
    $triggers->registrationCompleted($registration);
    $registration->update(['status' => EventRegistration::STATUS_CHECKED_IN, 'checked_in_at' => now()]);
    $triggers->registrationCheckedIn($registration, 'door-scan');
    $triggers->registrationCheckedIn($registration, 'door-scan');

    expect(EventNotificationLog::query()->count())->toBe(2);
});

test('rolled back transitions do not trigger rules', function (): void {
    [$tenant] = eventHost('trigger-rollback');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    createOccurrenceRule($event, EventNotificationRule::TRIGGER_ON_REGISTRATION);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    DB::connection('landlord')->beginTransaction();
    app(EventRuleTriggerService::class)->registrationCompleted($registration);
    DB::connection('landlord')->rollBack();

    expect(EventNotificationLog::query()->count())->toBe(0);
});

test('materials trigger only when immediately released and only once', function (): void {
    [$tenant] = eventHost('trigger-material');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    createOccurrenceRule($event, EventNotificationRule::TRIGGER_ON_MATERIALS);
    EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);
    $delayed = EventMaterial::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'release_at' => now()->addDay(),
    ]);
    $released = EventMaterial::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'release_at' => null,
    ]);
    $triggers = app(EventRuleTriggerService::class);

    $triggers->materialPublished($delayed);
    $triggers->materialPublished($released);
    $triggers->materialPublished($released);

    expect(EventNotificationLog::query()->count())->toBe(1)
        ->and(EventNotificationLog::query()->firstOrFail()->source_id)->toBe($released->id);
});
