<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventNotificationLog;
use App\Models\EventNotificationRule;
use App\Models\EventStaffAssignment;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Models\TenantNotificationSetting;
use App\Models\User;
use App\Services\Notifications\AutomatedNotificationDispatcher;
use App\Services\Notifications\NotificationDeliveryClaimService;
use App\Services\Notifications\NotificationGatewayService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Mail::fake();
    Queue::fake();
});

function organizerSafetyScenario(): array
{
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    app(App\Services\Tenancy\TenantContext::class)->setTenant($tenant);
    setPermissionsTeamId($tenant->id);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $rule = EventNotificationRule::create([
        'tenant_id' => $tenant->id, 'event_id' => $event->id,
        'name' => 'Staff reminder', 'subject' => 'Staff', 'body_template' => 'Staff only',
        'channels' => ['email'], 'target_role' => EventNotificationRule::ROLE_ORGANIZER,
        'target_audience' => 'all', 'is_active' => true,
    ]);
    $user = User::factory()->create(['tenant_id' => null, 'status' => 'active']);
    $user->tenants()->attach($tenant->id);
    $user->assignRole('Event Organiser');
    EventStaffAssignment::create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'user_id' => $user->id]);
    $settings = TenantNotificationSetting::forTenant($tenant->id);
    $settings->update(['email_monthly_limit' => 100, 'email_used_this_month' => 0, 'sms_enabled' => true]);

    return [$tenant, $event, $rule, $user, $settings];
}

function organizerSafetyClaim(Event $event, EventNotificationRule $rule, User $user, string $channel = 'email'): EventNotificationLog
{
    return app(NotificationDeliveryClaimService::class)->claim($event, $rule, 'manual', (string) Illuminate\Support\Str::uuid(), [
        'name' => 'Staff', 'email' => $user->email, 'phone' => '233200000000',
        'staff_user_id' => $user->id, 'recorded_email' => $user->email,
    ], $channel, ['subject' => 'Staff', 'body' => 'Private']);
}

it('selects secondary permitted staff and excludes unsafe primary affiliations', function (): void {
    [$tenant, $event, $rule, $user] = organizerSafetyScenario();
    foreach (['absent', 'revoked', 'suspended', 'inactive', 'unassigned', 'unpermitted'] as $case) {
        $other = User::factory()->create(['tenant_id' => $tenant->id, 'status' => $case === 'inactive' ? 'inactive' : 'active']);
        if ($case !== 'absent') {
            $other->allTenants()->attach($tenant->id, ['status' => in_array($case, ['revoked', 'suspended']) ? $case : 'active']);
        }
        if ($case !== 'unpermitted') {
            $other->assignRole('Event Organiser');
        }
        if ($case !== 'unassigned') {
            EventStaffAssignment::create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'user_id' => $other->id]);
        }
    }
    $recipients = app(AutomatedNotificationDispatcher::class)->resolveRecipients($rule, $event);
    expect(array_column($recipients, 'email'))->toBe([$user->email]);
    expect($recipients[0]['staff_user_id'])->toBe($user->id)
        ->and($recipients[0]['recorded_email'])->toBe($user->email);
});

it('freezes staff identity through dispatch normalization and claim', function (): void {
    [$tenant, $event, $rule, $user] = organizerSafetyScenario();
    app(AutomatedNotificationDispatcher::class)->dispatchBulkRule($rule, 'test');
    $log = EventNotificationLog::firstOrFail();
    expect($log->metadata['staff_user_id'])->toBe($user->id)
        ->and($log->metadata['recorded_email'])->toBe($user->email);
});

it('skips unsafe delayed staff before metering or transport', function (string $change, string $channel): void {
    [$tenant, $event, $rule, $user, $settings] = organizerSafetyScenario();
    TenantAddon::factory()->create([
        'tenant_id' => $tenant->id, 'addon_type' => TenantAddon::TYPE_SMS_PACK,
        'quantity' => 5, 'billing_interval' => TenantAddon::INTERVAL_ONE_OFF,
        'status' => TenantAddon::STATUS_ACTIVE,
    ]);
    $this->mock(App\Contracts\SmsGateway::class)->shouldNotReceive('send');
    $log = organizerSafetyClaim($event, $rule, $user, $channel);
    $log->load('event', 'rule');
    $user->load('tenants', 'roles');
    match ($change) {
        'revoked', 'suspended' => $user->allTenants()->updateExistingPivot($tenant->id, ['status' => $change]),
        'inactive' => $user->update(['status' => 'inactive']),
        'email' => $user->update(['email' => 'changed@example.com']),
        'assignment' => EventStaffAssignment::query()->delete(),
        'permission' => $user->removeRole('Event Organiser'),
        'missing' => $log->update(['metadata' => []]),
        'wrong_email' => $log->update(['recipient_email' => 'someone@example.com']),
    };
    $result = app(NotificationGatewayService::class)->deliver($log);
    expect($result['status'])->toBe(EventNotificationLog::STATUS_SKIPPED)
        ->and($result['cost'])->toBe(0)
        ->and($settings->fresh()->email_used_this_month)->toBe(0)
        ->and(app(App\Services\Sms\SmsAllowance::class)->remaining($tenant))->toBe(5);
    Mail::assertNothingSent();
})->with(['revoked', 'suspended', 'inactive', 'email', 'assignment', 'permission', 'missing', 'wrong_email'])->with(['email', 'sms']);

it('delivers unchanged active secondary staff including null global status', function (?string $status): void {
    [$tenant, $event, $rule, $user, $settings] = organizerSafetyScenario();
    $user->update(['status' => $status]);
    $log = organizerSafetyClaim($event, $rule, $user);
    expect(app(NotificationGatewayService::class)->deliver($log)['status'])->toBe(EventNotificationLog::STATUS_SENT)
        ->and($settings->fresh()->email_used_this_month)->toBe(1);
    Mail::assertSent(App\Mail\Events\AutomatedNotificationMail::class, 1);
})->with(['active', null]);

it('rechecks marked synchronous staff even without a rule', function (): void {
    [$tenant, $event, $rule, $user, $settings] = organizerSafetyScenario();
    $user->allTenants()->updateExistingPivot($tenant->id, ['status' => 'revoked']);
    $results = app(NotificationGatewayService::class)->dispatch($event, null, [
        'name' => 'Staff', 'email' => $user->email, 'staff_user_id' => $user->id, 'recorded_email' => $user->email,
    ], ['email'], ['subject' => 'Staff', 'body' => 'Private']);
    expect($results['email']['status'])->toBe(EventNotificationLog::STATUS_SKIPPED)
        ->and($settings->fresh()->email_used_this_month)->toBe(0);
    Mail::assertNothingSent();
});

it('keeps frozen staff guards after rule changes and never infers identity from matching contact', function (): void {
    [$tenant, $event, $rule, $user, $settings] = organizerSafetyScenario();
    $marked = organizerSafetyClaim($event, $rule, $user);
    $rule->update(['target_role' => EventNotificationRule::ROLE_ATTENDEE]);
    $user->update(['email' => 'replacement@example.com']);
    $other = User::factory()->create(['email' => $marked->recipient_email, 'status' => 'active']);
    $other->tenants()->attach($tenant->id);
    $other->assignRole('Org Superadmin');
    expect(app(NotificationGatewayService::class)->deliver($marked)['status'])->toBe(EventNotificationLog::STATUS_SKIPPED);

    $rule->update(['target_role' => EventNotificationRule::ROLE_ORGANIZER]);
    foreach ([['recorded_email' => $other->email], ['staff_user_id' => $other->id], ['staff_user_id' => null, 'recorded_email' => $other->email], ['staff_user_id' => 'not-an-id', 'recorded_email' => $other->email], ['staff_user_id' => ['invalid'], 'recorded_email' => $other->email]] as $metadata) {
        $log = organizerSafetyClaim($event, $rule, $other);
        $log->update(['metadata' => $metadata]);
        expect(app(NotificationGatewayService::class)->deliver($log)['status'])->toBe(EventNotificationLog::STATUS_SKIPPED);
    }
    expect($settings->fresh()->email_used_this_month)->toBe(0);
    Mail::assertNothingSent();
});

it('retains invalid staff markers as null evidence so claims fail closed', function (): void {
    [$tenant, $event, $rule, $user] = organizerSafetyScenario();
    $rule->update(['target_role' => EventNotificationRule::ROLE_ATTENDEE]);
    $log = app(NotificationDeliveryClaimService::class)->claim($event, $rule, 'manual', 'invalid-marker', [
        'name' => 'Staff', 'email' => $user->email,
        'staff_user_id' => ['invalid'], 'recorded_email' => ['invalid'],
    ], 'email', ['subject' => 'Staff', 'body' => 'Private']);
    expect($log->metadata)->toHaveKeys(['staff_user_id', 'recorded_email'])
        ->and($log->metadata['staff_user_id'])->toBeNull()
        ->and($log->metadata['recorded_email'])->toBeNull()
        ->and(app(NotificationGatewayService::class)->deliver($log)['status'])->toBe(EventNotificationLog::STATUS_SKIPPED);
    Mail::assertNothingSent();
});
