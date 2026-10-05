<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/**
 * A superadmin can take down an abusive event or marketplace listing: it
 * leaves the public, its owner cannot republish it, the reason is kept, and
 * restoring puts back the status it had.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);

    $this->superadmin = User::factory()->create(['tenant_id' => null]);
    setPermissionsTeamId(null);
    $this->superadmin->assignRole(Role::query()->where('name', 'Superadmin')->whereNull('tenant_id')->firstOrFail());
});

test('a taken-down event leaves the public page and the organiser cannot republish it', function (): void {
    [$tenant, $owner] = eventHost('scam-org');
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'status' => Event::STATUS_PUBLISHED, 'slug' => 'free-iphones']);
    $host = eventSubdomainHost('scam-org');

    $this->get("http://{$host}/e/free-iphones", ['HTTP_HOST' => $host])->assertOk();

    $this->actingAs($this->superadmin)
        ->post(route('admin.takedowns.store', ['event', $event->id]), ['reason' => 'Fraudulent giveaway reported by attendees'])
        ->assertSessionHas('success');

    $event->refresh();
    expect($event->status)->toBe(Event::STATUS_SUSPENDED)
        ->and($event->takedown_reason)->toBe('Fraudulent giveaway reported by attendees');

    auth()->logout();
    $this->get("http://{$host}/e/free-iphones", ['HTTP_HOST' => $host])->assertNotFound();

    $this->actingAs($owner)
        ->put("http://{$host}/events/{$event->id}", [
            'name' => 'Renamed while down',
            'status' => Event::STATUS_PUBLISHED,
            'starts_at' => $event->starts_at->toDateTimeString(),
            'ends_at' => $event->ends_at->toDateTimeString(),
            'timezone' => 'Africa/Accra',
            'location_type' => Event::LOCATION_VIRTUAL,
            'virtual_link' => 'https://meet.example.com/abc',
            'ticket_price' => 0,
            'currency' => 'GHS',
            'virtual_link' => 'https://meet.example.com/abc',
            'ticket_price' => 0,
            'currency' => 'GHS',
        ], ['HTTP_HOST' => $host])
        ->assertSessionHasNoErrors();

    // The edit itself went through; only the republish was held back.
    expect($event->fresh()->name)->toBe('Renamed while down')
        ->and($event->fresh()->status)->toBe(Event::STATUS_SUSPENDED);

    $this->actingAs($owner)
        ->get("http://{$host}/events/{$event->id}", ['HTTP_HOST' => $host])
        ->assertInertia(fn (Assert $page) => $page->where('event.status', Event::STATUS_SUSPENDED)
            ->where('event.takedown_reason', 'Fraudulent giveaway reported by attendees'));
});

test('restoring puts back the status the event had', function (): void {
    [$tenant] = eventHost('real-org');
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'status' => Event::STATUS_PUBLISHED]);

    $this->actingAs($this->superadmin)->post(route('admin.takedowns.store', ['event', $event->id]), ['reason' => 'Reported in error, checking']);
    $this->actingAs($this->superadmin)->delete(route('admin.takedowns.destroy', ['event', $event->id]))->assertSessionHas('success');

    $event->refresh();
    expect($event->status)->toBe(Event::STATUS_PUBLISHED)
        ->and($event->taken_down_at)->toBeNull();
    $this->assertDatabaseHas('activity_log', ['description' => "Restored event \"{$event->name}\""], 'landlord');
});

test('organisers cannot mark their own event suspended to dodge the rules', function (): void {
    [$tenant, $owner] = eventHost('own-org');
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'status' => Event::STATUS_DRAFT]);
    $host = eventSubdomainHost('own-org');

    $this->actingAs($owner)->put("http://{$host}/events/{$event->id}", [
        'name' => 'Renamed draft',
        'status' => Event::STATUS_SUSPENDED,
        'starts_at' => $event->starts_at->toDateTimeString(),
        'ends_at' => $event->ends_at->toDateTimeString(),
        'timezone' => 'Africa/Accra',
        'location_type' => Event::LOCATION_VIRTUAL,
        'virtual_link' => 'https://meet.example.com/abc',
        'ticket_price' => 0,
        'currency' => 'GHS',
    ], ['HTTP_HOST' => $host])->assertSessionHasNoErrors();

    expect($event->fresh()->name)->toBe('Renamed draft')
        ->and($event->fresh()->status)->toBe(Event::STATUS_DRAFT)
        ->and($event->fresh()->taken_down_at)->toBeNull();
});

test('a taken-down listing stays suspended whatever its owner sets', function (): void {
    $shop = Shop::factory()->create();
    $listing = StoreListing::factory()->published()->create(['shop_id' => $shop->id]);

    $this->actingAs($this->superadmin)
        ->post(route('admin.takedowns.store', ['listing', $listing->id]), ['reason' => 'Photos copied from another venue'])
        ->assertSessionHas('success');

    expect($listing->fresh()->status)->toBe(StoreListing::STATUS_SUSPENDED)
        ->and($listing->fresh()->status_before_takedown)->toBe(StoreListing::STATUS_PUBLISHED);

    $this->actingAs($this->superadmin)->delete(route('admin.takedowns.destroy', ['listing', $listing->id]));

    expect($listing->fresh()->status)->toBe(StoreListing::STATUS_PUBLISHED);
});

test('a takedown needs a reason, and only superadmins can do it', function (): void {
    [$tenant, $owner] = eventHost('some-org');
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'status' => Event::STATUS_PUBLISHED]);

    $this->actingAs($this->superadmin)
        ->post(route('admin.takedowns.store', ['event', $event->id]), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    $this->actingAs($owner)
        ->post(route('admin.takedowns.store', ['event', $event->id]), ['reason' => 'Taking down a rival'])
        ->assertForbidden();

    expect($event->fresh()->status)->toBe(Event::STATUS_PUBLISHED);
});
