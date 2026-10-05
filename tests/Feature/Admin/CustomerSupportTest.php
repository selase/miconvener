<?php

declare(strict_types=1);

use App\Models\BillingEmail;
use App\Models\Event;
use App\Models\EventBlast;
use App\Models\EventBlastRecipient;
use App\Models\EventNotificationLog;
use App\Models\EventRegistration;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Support views: Customer health lists every organisation from real signals,
 * the customer view puts one organisation on one page, and Message delivery
 * answers "did this person get our message?" across organisations.
 */
beforeEach(function (): void {
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->seed(Database\Seeders\PermissionsSeeder::class);
    $this->seed(Database\Seeders\EventPackageSeeder::class);
    $this->superadmin = User::factory()->create(['tenant_id' => null]);
    setPermissionsTeamId(null);
    $this->superadmin->assignRole(Role::query()->where('name', 'Superadmin')->whereNull('tenant_id')->firstOrFail());
});

function supportTenant(string $name, string $plan = 'starter'): Tenant
{
    return Tenant::factory()->create([
        'name' => $name,
        'isolation_mode' => 'shared',
        'package_id' => Package::query()->where('slug', $plan)->value('id'),
    ]);
}

function deliveryLog(Tenant $tenant, array $attributes): EventNotificationLog
{
    return EventNotificationLog::withoutGlobalScopes()->create($attributes + [
        'tenant_id' => $tenant->id,
        'event_id' => Event::factory()->create(['tenant_id' => $tenant->id, 'starts_at' => now()->subMonth()])->id,
        'notification_type' => 'reminder',
        'dedupe_key' => uniqid('k', true),
        'channel' => EventNotificationLog::CHANNEL_EMAIL,
        'status' => EventNotificationLog::STATUS_SENT,
        'subject' => 'See you tomorrow',
        'message' => 'Doors open at 8.',
        'sent_at' => now(),
    ]);
}

test('customer health lists organisations needing attention first, from real signals', function (): void {
    supportTenant('Calm Org');
    $late = supportTenant('Late Payer');
    Subscription::query()->create(['tenant_id' => $late->id, 'name' => 'default', 'provider_id' => 'ps_1', 'provider_status' => Subscription::STATUS_PAST_DUE, 'provider_plan' => 'starter_month', 'current_period_end' => now()->subDays(2)]);
    $noisy = supportTenant('Failing Texts');
    deliveryLog($noisy, ['channel' => EventNotificationLog::CHANNEL_SMS, 'recipient_phone' => '+233241234567', 'status' => EventNotificationLog::STATUS_FAILED]);

    $this->actingAs($this->superadmin)
        ->get(route('health.tenants'))
        ->assertOk()
        ->assertSee('2 of 3 organisations need attention')
        ->assertSeeInOrder(['Failing Texts', 'Late Payer', 'Calm Org'])
        ->assertSee('Past due')
        ->assertDontSee('Healthy');
});

test('the customer view shows plan, billing, messaging and events on one page', function (): void {
    $tenant = supportTenant('Accra Summit');
    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'owner@accra.test']);
    $tenant->users()->attach($owner->id);
    Subscription::query()->create(['tenant_id' => $tenant->id, 'name' => 'default', 'provider_id' => 'ps_1', 'provider_status' => Subscription::STATUS_ACTIVE, 'provider_plan' => 'starter_month', 'current_period_end' => now()->addDays(10)]);
    Event::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Health Tech Day', 'starts_at' => now()->addWeek()]);
    deliveryLog($tenant, ['recipient_email' => 'guest@accra.test', 'status' => EventNotificationLog::STATUS_FAILED]);

    $this->actingAs($this->superadmin)
        ->get(route('health.tenants.show', $tenant->uuid))
        ->assertOk()
        ->assertSee('Starter')
        ->assertSee('Paid')
        ->assertSee('owner@accra.test')
        ->assertSee('Health Tech Day')
        ->assertSee('EMAIL failed: 1');
});

test('message delivery finds a person by email across organisations, with the reason a message failed', function (): void {
    $first = supportTenant('Accra Summit');
    $second = supportTenant('Kumasi Expo');
    deliveryLog($first, ['recipient_email' => 'Ama@Example.com', 'status' => EventNotificationLog::STATUS_FAILED, 'metadata' => ['error' => 'Mailbox does not exist']]);
    $event = Event::factory()->create(['tenant_id' => $second->id]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $second->id, 'event_id' => $event->id, 'email' => 'ama@example.com']);
    $blast = EventBlast::factory()->create(['tenant_id' => $second->id, 'event_id' => $event->id, 'subject' => 'Programme update']);
    EventBlastRecipient::withoutGlobalScopes()->create(['tenant_id' => $second->id, 'blast_id' => $blast->id, 'registration_id' => $registration->id]);
    BillingEmail::query()->create(['tenant_id' => $first->id, 'type' => BillingEmail::TYPE_PAYMENT_RECEIPT, 'dedupe_key' => 'r1', 'recipients' => ['ama@example.com']]);

    $this->actingAs($this->superadmin)
        ->get(route('admin.messages.index', ['q' => 'ama@example.com']))
        ->assertOk()
        ->assertSee('Accra Summit')
        ->assertSee('Kumasi Expo')
        ->assertSee('Mailbox does not exist')
        ->assertSee('Programme update')
        ->assertSee('billing: payment receipt');
});

test('message delivery finds a phone number however it is typed', function (string $typed): void {
    deliveryLog(supportTenant('Accra Summit'), ['channel' => EventNotificationLog::CHANNEL_SMS, 'recipient_phone' => '+233241234567', 'subject' => null, 'message' => 'Your ticket code is 4821']);

    $this->actingAs($this->superadmin)
        ->get(route('admin.messages.index', ['q' => $typed]))
        ->assertOk()
        ->assertSee('Your ticket code is 4821');
})->with(['0241234567', '024 123 4567', '+233 24 123 4567', '233241234567']);

test('a search too short to identify anyone returns nothing', function (): void {
    deliveryLog(supportTenant('Accra Summit'), ['recipient_email' => 'ama@example.com']);

    $this->actingAs($this->superadmin)
        ->get(route('admin.messages.index', ['q' => '024']))
        ->assertOk()
        ->assertSee('Nothing found');
});

test('organisation admins cannot reach the support views', function (): void {
    $tenant = supportTenant('Accra Summit');
    $member = User::factory()->create(['tenant_id' => $tenant->id]);
    setActiveTenantForTest($member);
    $member->assignRole('Org Superadmin');

    $this->actingAs($member)->get(route('health.tenants'))->assertForbidden();
    $this->actingAs($member)->get(route('health.tenants.show', $tenant->uuid))->assertForbidden();
    $this->actingAs($member)->get(route('admin.messages.index', ['q' => 'ama@example.com']))->assertForbidden();
});
