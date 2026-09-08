<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Jobs\Events\SendEventBlastJob;
use App\Models\Event;
use App\Models\EventBlast;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\TenantFeatureUsage;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('sending a registration confirmation email records email_credits usage', function () {
    Mail::fake();

    $tenant = Tenant::factory()->create(['slug' => 'acme5', 'isolation_mode' => 'shared']);
    $host = 'acme5.'.mb_ltrim((string) config('session.domain'), '.');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ], ['HTTP_HOST' => $host]);

    $usage = TenantFeatureUsage::where('tenant_id', $tenant->id)
        ->where('feature_slug', 'email_credits')
        ->whereNull('period_start')
        ->value('used_count');

    expect($usage)->toBe(1);
});

/**
 * A blast is the only action in the product that turns one click into hundreds
 * of sends. It was also the only email path that never touched the meter, so a
 * free-tier organizer could send without bound.
 */
test('a blast larger than the remaining email credits is refused', function () {
    Bus::fake();

    $tenant = Tenant::factory()->create(['slug' => 'blast-cap', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    TenantFeature::create([
        'tenant_id' => $tenant->id,
        'feature_key' => 'email_credits',
        'enabled' => true,
        'meta' => ['type' => 'limit', 'value' => 2],
    ]);

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    EventRegistration::factory()->count(3)->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $host = 'blast-cap.'.mb_ltrim((string) config('session.domain'), '.');

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/blasts", [
        'subject' => 'Doors open at 9',
        'body' => 'See you there.',
        'audience' => 'confirmed',
    ], ['HTTP_HOST' => $host])->assertStatus(422);

    expect(EventBlast::where('event_id', $event->id)->count())->toBe(0);
    Bus::assertNotDispatched(SendEventBlastJob::class);
});

test('a blast within the remaining email credits is accepted', function () {
    Bus::fake();

    $tenant = Tenant::factory()->create(['slug' => 'blast-ok', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    TenantFeature::create([
        'tenant_id' => $tenant->id,
        'feature_key' => 'email_credits',
        'enabled' => true,
        'meta' => ['type' => 'limit', 'value' => 200],
    ]);

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    EventRegistration::factory()->count(3)->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $host = 'blast-ok.'.mb_ltrim((string) config('session.domain'), '.');

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/blasts", [
        'subject' => 'Doors open at 9',
        'body' => 'See you there.',
        'audience' => 'confirmed',
    ], ['HTTP_HOST' => $host])->assertSuccessful();

    Bus::assertDispatched(SendEventBlastJob::class);
});

test('sending a blast charges one email credit per recipient', function () {
    Mail::fake();

    $tenant = Tenant::factory()->create(['slug' => 'blast-charge', 'isolation_mode' => 'shared']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    EventRegistration::factory()->count(3)->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $blast = EventBlast::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'sent_by' => User::factory()->create(['tenant_id' => $tenant->id])->id,
        'subject' => 'Doors open at 9',
        'body' => 'See you there.',
        'audience' => 'confirmed',
        'audience_label' => 'Confirmed',
        'recipients_count' => 3,
        'status' => EventBlast::STATUS_SCHEDULED,
    ]);

    (new SendEventBlastJob($blast))->handle();

    $usage = TenantFeatureUsage::where('tenant_id', $tenant->id)
        ->where('feature_slug', 'email_credits')
        ->whereNull('period_start')
        ->value('used_count');

    expect($usage)->toBe(3);
});
