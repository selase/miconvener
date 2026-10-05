<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Services\Events\EventContributionService;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * What MiConvener keeps from a voluntary contribution is a setting, and the
 * organizer is told the actual figure before turning contributions on. By
 * default it is the event's ticket commission.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function contributionFeeEvent(): Event
{
    [$tenant] = eventHost('grace-chapel');

    return Event::factory()->create([
        'tenant_id' => $tenant->id,
        'currency' => 'GHS',
        'platform_fee_percentage' => '5.00',
        'allow_contributions' => true,
    ]);
}

test('by default a contribution carries the event ticket commission, and says so', function (): void {
    $event = contributionFeeEvent();
    $service = app(EventContributionService::class);

    expect($service->platformFeeFor($event, 10000))->toBe(500)
        ->and($service->feeNoteFor($event))->toContain('5%');
});

test('a separate contribution rate can be set, applied without the ticket cap', function (): void {
    config()->set('services.contributions.platform_fee_percentage', 2);
    $event = contributionFeeEvent();
    $service = app(EventContributionService::class);

    expect($service->platformFeeFor($event, 500000))->toBe(10000)
        ->and($service->feeNoteFor($event))->toContain('2%');
});

test('contributions can be set to carry no commission at all', function (): void {
    config()->set('services.contributions.platform_fee_percentage', 0);
    $event = contributionFeeEvent();
    $service = app(EventContributionService::class);

    expect($service->platformFeeFor($event, 10000))->toBe(0)
        ->and($service->feeNoteFor($event))->toContain('no MiConvener commission');
});

test('the organizer sees the contribution commission in the event console', function (): void {
    [$tenant, $user] = eventHost('grace-chapel');
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'platform_fee_percentage' => '5.00']);
    $host = eventSubdomainHost('grace-chapel');

    $this->actingAs($user)->get("http://{$host}/events/{$event->id}", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('event.contribution_fee_note', fn (string $note): bool => str_contains($note, '5%')));
});
