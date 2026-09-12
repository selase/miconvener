<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Event;
use App\Models\EventAbstract;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

/**
 * The newer tenant controllers resolve their models through route-model binding
 * rather than an explicit tenant_id filter, which is only safe while the global
 * TenantScope is actually applied. This probes that directly.
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('a tenant cannot reach another tenants event through route model binding', function () {
    $victim = Tenant::factory()->create(['slug' => 'victim-co', 'isolation_mode' => 'shared']);
    $attacker = Tenant::factory()->create(['slug' => 'attacker-co', 'isolation_mode' => 'shared']);

    $victimEvent = Event::factory()->published()->create([
        'tenant_id' => $victim->id,
        'name' => 'Victim Confidential Summit',
    ]);

    $user = User::factory()->create(['tenant_id' => $attacker->id]);
    setPermissionsTeamId($attacker->id);
    $user->assignRole('Org Superadmin');
    $attacker->users()->attach($user->id);

    $host = 'attacker-co.'.mb_ltrim((string) config('session.domain'), '.');

    $response = $this->actingAs($user)
        ->get("http://{$host}/events/{$victimEvent->id}/abstracts", ['HTTP_HOST' => $host]);

    expect($response->getStatusCode())->not->toBe(200);
    $response->assertDontSee('Victim Confidential Summit');
});

test('a tenant cannot reach another tenants abstract', function () {
    $victim = Tenant::factory()->create(['slug' => 'victim2-co', 'isolation_mode' => 'shared']);
    $attacker = Tenant::factory()->create(['slug' => 'attacker2-co', 'isolation_mode' => 'shared']);

    $victimEvent = Event::factory()->published()->create(['tenant_id' => $victim->id]);
    $attackerEvent = Event::factory()->published()->create(['tenant_id' => $attacker->id]);

    $victimAbstract = EventAbstract::create([
        'tenant_id' => $victim->id,
        'event_id' => $victimEvent->id,
        'title' => 'Unpublished Trial Results',
        'body' => 'Confidential',
        'status' => EventAbstract::STATUS_SUBMITTED,
        'code' => 'ABS-PROBE1',
    ]);

    $user = User::factory()->create(['tenant_id' => $attacker->id]);
    setPermissionsTeamId($attacker->id);
    $user->assignRole('Org Superadmin');
    $attacker->users()->attach($user->id);

    $host = 'attacker2-co.'.mb_ltrim((string) config('session.domain'), '.');

    $response = $this->actingAs($user)
        ->get("http://{$host}/events/{$attackerEvent->id}/abstracts/{$victimAbstract->id}", ['HTTP_HOST' => $host]);

    expect($response->getStatusCode())->not->toBe(200);
    $response->assertDontSee('Unpublished Trial Results');
});
