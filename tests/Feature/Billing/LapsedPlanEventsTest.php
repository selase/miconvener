<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventTicketType;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\TenantFeatureUsage;
use App\Models\User;
use App\Services\Billing\SubscriptionProvisioningService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

/**
 * An event already live when its organizer's plan lapses keeps working until it
 * ends. Before this, a lapse closed registration on live events, raised their
 * commission from 2% to 5% mid-sale, stopped the organizer editing them and
 * locked them out of refunds — while paid sales carried on.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);
    config(['services.settlement.paystack.secret_key' => 'sk_settlement']);
    Mail::fake();
});

/**
 * @return array{0: Tenant, 1: User, 2: string}
 */
function growthOrganizer(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'lapsing', 'isolation_mode' => 'shared', 'package_id' => Package::query()->where('slug', 'growth')->value('id')]);
    $tenant->syncFeaturesFromPackage();
    $owner = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($owner->id);
    setPermissionsTeamId($tenant->id);
    $owner->assignRole('Org Superadmin');

    return [$tenant, $owner, 'lapsing.'.mb_ltrim((string) config('session.domain'), '.')];
}

function liveEvent(Tenant $tenant, int $price = 15000, array $attributes = []): Event
{
    return Event::factory()->published()->paid($price)->create($attributes + ['tenant_id' => $tenant->id, 'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(6)]);
}

function registrationsThisMonth(Tenant $tenant, int $count): void
{
    TenantFeatureUsage::query()->updateOrCreate(
        ['tenant_id' => $tenant->id, 'feature_slug' => 'event_registrations', 'period_start' => null],
        ['period_end' => null, 'used_count' => $count],
    );
}

function lapse(Tenant $tenant): Tenant
{
    app(SubscriptionProvisioningService::class)->switchToFree($tenant->fresh());

    return $tenant->fresh();
}

test('a lapse protects live events only, and freezes the commission they started with', function (): void {
    [$tenant] = growthOrganizer();
    $live = liveEvent($tenant);
    $draft = Event::factory()->paid(15000)->create(['tenant_id' => $tenant->id, 'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(6)]);
    $over = liveEvent($tenant, attributes: ['starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(2)]);

    expect($live->effectivePlatformFeePercentage())->toBe(2.0);

    $tenant = lapse($tenant);

    expect($live->fresh()->isGrandfathered())->toBeTrue()
        ->and($live->fresh()->effectivePlatformFeePercentage())->toBe(2.0)
        ->and($draft->fresh()->isGrandfathered())->toBeFalse()
        ->and($draft->fresh()->effectivePlatformFeePercentage())->toBe(5.0)
        ->and($over->fresh()->isGrandfathered())->toBeFalse();

    $newEvent = Event::factory()->create(['tenant_id' => $tenant->id]);
    expect($newEvent->effectivePlatformFeePercentage())->toBe(5.0);
});

test('a live event keeps registering after a lapse even past the Free ceiling; a new one does not', function (): void {
    [$tenant, , $host] = growthOrganizer();
    $live = liveEvent($tenant, 0);
    registrationsThisMonth($tenant, 120);
    $tenant = lapse($tenant);
    $later = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addHour()]);

    $this->post("http://{$host}/e/{$live->slug}/register", ['full_name' => 'Ama Mensah', 'email' => 'ama@example.com'], ['HTTP_HOST' => $host]);
    $this->post("http://{$host}/e/{$later->slug}/register", ['full_name' => 'Kofi Boateng', 'email' => 'kofi@example.com'], ['HTTP_HOST' => $host]);

    expect($live->registrations()->count())->toBe(1)
        ->and($later->registrations()->count())->toBe(0);
});

test('a live paid event keeps selling after a lapse; a paid event that was not live cannot sell', function (): void {
    [$tenant, , $host] = growthOrganizer();
    $live = liveEvent($tenant);
    $draft = Event::factory()->paid(15000)->create(['tenant_id' => $tenant->id, 'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(6)]);
    lapse($tenant);
    $draft->forceFill(['status' => Event::STATUS_PUBLISHED])->saveQuietly();

    $this->post("http://{$host}/e/{$live->slug}/register", ['full_name' => 'Ama Mensah', 'email' => 'ama@example.com'], ['HTTP_HOST' => $host]);
    $this->post("http://{$host}/e/{$draft->slug}/register", ['full_name' => 'Kofi Boateng', 'email' => 'kofi@example.com'], ['HTTP_HOST' => $host])
        ->assertSessionHas('error', 'This event is not selling paid tickets right now. Please contact the organizer.');

    expect($live->registrations()->first()?->amount)->toBe(15000)
        ->and($draft->registrations()->count())->toBe(0);
});

test('the organizer can still edit a live paid event and its paid tickets, but cannot add new paid ones', function (): void {
    [$tenant, $owner, $host] = growthOrganizer();
    $event = liveEvent($tenant);
    $ticket = EventTicketType::query()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'name' => 'Regular', 'price' => 15000, 'capacity' => 100, 'is_active' => true]);
    lapse($tenant);

    $this->actingAs($owner)->put("http://{$host}/events/{$event->id}", [
        'name' => 'Summit (typo fixed)', 'description' => 'Details', 'status' => 'published',
        'starts_at' => $event->starts_at->toDateTimeString(), 'ends_at' => $event->ends_at->toDateTimeString(),
        'timezone' => 'Africa/Accra', 'location_type' => 'in_person', 'address' => '1 Main Street', 'ticket_price' => 15000, 'currency' => 'GHS',
    ], ['HTTP_HOST' => $host])->assertSessionHasNoErrors();

    $this->putJson("http://{$host}/events/{$event->id}/ticket-types/{$ticket->id}", ['name' => 'Regular admission', 'price' => 15000, 'capacity' => 120, 'is_active' => true], ['HTTP_HOST' => $host])
        ->assertSuccessful();

    $this->postJson("http://{$host}/events/{$event->id}/ticket-types", ['name' => 'VIP', 'price' => 50000, 'capacity' => 10, 'is_active' => true], ['HTTP_HOST' => $host])
        ->assertStatus(422);

    expect($event->fresh()->name)->toBe('Summit (typo fixed)')
        ->and($ticket->fresh()->name)->toBe('Regular admission');
});

test('finance and payment settings stay open after a lapse for money already collected', function (): void {
    [$tenant, $owner, $host] = growthOrganizer();
    liveEvent($tenant);
    lapse($tenant);

    $this->actingAs($owner);
    $this->get("http://{$host}/finance", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tenant.features.finance', true)->where('tenant.features.paid_tickets', false));
    $this->get("http://{$host}/settings/payments", ['HTTP_HOST' => $host])->assertOk();
});

test('a Free tenant that never sold tickets still has no finance pages', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'always-free', 'isolation_mode' => 'shared', 'package_id' => Package::query()->where('slug', 'free')->value('id')]);
    $tenant->syncFeaturesFromPackage();

    expect($tenant->handlesTicketMoney())->toBeFalse();
});

test('a downgrade to a cheaper paid plan keeps a live event at its starting commission', function (): void {
    [$tenant] = growthOrganizer();
    $event = liveEvent($tenant);

    $tenant->update(['package_id' => Package::query()->where('slug', 'starter')->value('id')]);

    expect($event->fresh()->effectivePlatformFeePercentage())->toBe(2.0)
        ->and($event->fresh()->isGrandfathered())->toBeTrue();
});

test('an upgrade lets live events enjoy the lower commission', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'upgrading', 'isolation_mode' => 'shared', 'package_id' => Package::query()->where('slug', 'starter')->value('id')]);
    $event = liveEvent($tenant);

    $tenant->update(['package_id' => Package::query()->where('slug', 'growth')->value('id')]);

    expect($event->fresh()->effectivePlatformFeePercentage())->toBe(2.0)
        ->and($event->fresh()->terms_locked_at)->toBeNull()
        ->and($event->fresh()->isGrandfathered())->toBeFalse();
});

test('a superadmin commission set on the event itself still wins over frozen terms', function (): void {
    [$tenant] = growthOrganizer();
    $event = liveEvent($tenant);
    lapse($tenant);

    $event->fresh()->update(['platform_fee_percentage' => 0]);

    expect($event->fresh()->effectivePlatformFeePercentage())->toBe(0.0);
});
