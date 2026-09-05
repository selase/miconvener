<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventBadgePrint;
use App\Models\EventRegistration;
use App\Models\EventTicketType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function badgeHost(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

test('host sees a printable badge with a QR image for each confirmed registration only', function () {
    [$tenant, $user] = badgeHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $confirmed = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'full_name' => 'Abena Owusu',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);
    EventRegistration::factory()->pendingPayment()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->getJson("http://{$host}/events/{$event->id}/badges", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $badges = $response->json('badges');
    expect($badges)->toHaveCount(1);
    expect($badges[0]['id'])->toBe($confirmed->id);
    expect($badges[0]['full_name'])->toBe('Abena Owusu');
    expect($badges[0]['qr_image'])->toStartWith('data:image/svg+xml;base64,');
    expect($badges[0]['badge_tier'])->toBe('general');
    expect($badges[0]['print_count'])->toBe(0);
});

test('a badge inherits the badge tier of its ticket type', function () {
    [$tenant, $user] = badgeHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $ticketType = EventTicketType::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'badge_tier' => EventTicketType::TIER_SPEAKER]);
    EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'ticket_type_id' => $ticketType->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->getJson("http://{$host}/events/{$event->id}/badges", ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($response->json('badges.0.badge_tier'))->toBe('speaker');
});

test('the badge tier of a paid ticket type on a published event can be changed without a payment gateway connected', function () {
    [$tenant, $user] = badgeHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $ticketType = EventTicketType::factory()->paid(5000)->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->patchJson("http://{$host}/events/{$event->id}/ticket-types/{$ticketType->id}/badge-tier", [
        'badge_tier' => EventTicketType::TIER_VIP,
    ], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($ticketType->fresh()->badge_tier)->toBe('vip');
});

test('setting an invalid badge tier is rejected', function () {
    [$tenant, $user] = badgeHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/ticket-types", [
        'name' => 'Faculty',
        'price' => 0,
        'badge_tier' => 'royalty',
    ], ['HTTP_HOST' => $host])->assertStatus(422);
});

test('printing badges logs an audit entry per registration and increments the print count', function () {
    [$tenant, $user] = badgeHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $first = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'full_name' => 'Ama Boateng', 'status' => EventRegistration::STATUS_CONFIRMED]);
    $second = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'full_name' => 'Kojo Mensah', 'status' => EventRegistration::STATUS_CONFIRMED]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/badges/print-log", [
        'registration_ids' => [$first->id, $second->id],
    ], ['HTTP_HOST' => $host])->assertOk();

    // Reprinting just one of them.
    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/badges/print-log", [
        'registration_ids' => [$first->id],
    ], ['HTTP_HOST' => $host])->assertOk();

    expect(EventBadgePrint::where('registration_id', $first->id)->count())->toBe(2);
    expect(EventBadgePrint::where('registration_id', $second->id)->count())->toBe(1);

    $response = $this->actingAs($user)->getJson("http://{$host}/events/{$event->id}/badges", ['HTTP_HOST' => $host]);
    $badges = collect($response->json('badges'))->keyBy('id');
    expect($badges[$first->id]['print_count'])->toBe(2);
    expect($badges[$second->id]['print_count'])->toBe(1);

    expect($response->json('recent_prints'))->toHaveCount(3);
    expect($response->json('recent_prints.0.printed_by'))->not->toBe('Unknown');
});

test('a host without read event permission cannot view badges', function () {
    [$tenant] = badgeHost();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $tenant->users()->attach($user->id); // no role assigned

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->getJson("http://{$host}/events/{$event->id}/badges", ['HTTP_HOST' => $host])
        ->assertForbidden();
});
