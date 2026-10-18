<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function benefitSettingsManager(): array
{
    [$tenant] = eventHost('benefits');
    foreach (['community', 'community_event_benefits'] as $key) {
        $tenant->features()->create(['feature_key' => $key, 'enabled' => true]);
    }
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($user->id);
    setPermissionsTeamId($tenant->id);
    $user->givePermissionTo('update event');
    test()->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('benefits')]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'ticket_price' => 0]);

    return [$tenant, $user, $event, 'http://'.eventSubdomainHost('benefits').'/events/'.$event->id.'/member-benefits'];
}

it('defaults to unrestricted and permits an explicit zero member price', function (): void {
    [, , $event, $url] = benefitSettingsManager();
    expect($event->members_only)->toBeFalse()->and($event->member_ticket_price)->toBeNull();
    $this->patchJson($url, ['members_only' => true, 'member_ticket_price' => 0])->assertSuccessful();
    expect($event->fresh()->members_only)->toBeTrue()->and($event->fresh()->member_ticket_price)->toBe(0);
    $this->patchJson($url, ['members_only' => false, 'member_ticket_price' => null])->assertSuccessful();
    expect($event->fresh()->member_ticket_price)->toBeNull();
});

it('rejects invalid member price or an unauthorised organiser', function (int|string $price): void {
    [, , , $url] = benefitSettingsManager();
    $this->patchJson($url, ['members_only' => true, 'member_ticket_price' => $price])->assertUnprocessable();
})->with([-1, 1, '0.50']);

it('enforces update permission, rollout and event tenant boundary', function (): void {
    [$tenant, $user, , $url] = benefitSettingsManager();
    $other = Event::factory()->create();
    $this->patchJson('http://'.eventSubdomainHost('benefits').'/events/'.$other->id.'/member-benefits', ['members_only' => true])->assertNotFound();
    $tenant->features()->where('feature_key', 'community_event_benefits')->update(['enabled' => false]);
    $this->patchJson($url, ['members_only' => true])->assertForbidden();
    $tenant->features()->where('feature_key', 'community_event_benefits')->update(['enabled' => true]);
    $user->revokePermissionTo('update event');
    $this->patchJson($url, ['members_only' => true])->assertForbidden();
});

it('validates nullable and zero member prices on ticket types without bypassing rollout', function (): void {
    [$tenant, , $event] = benefitSettingsManager();
    $url = 'http://'.eventSubdomainHost('benefits').'/events/'.$event->id.'/ticket-types';
    $response = $this->postJson($url, ['name' => 'Member admission', 'price' => 0, 'member_price' => 0])->assertSuccessful();
    expect($response->json('member_price'))->toBe(0);
    $this->putJson($url.'/'.$response->json('id'), ['name' => 'Updated', 'price' => 0, 'member_price' => 0])->assertSuccessful();
    $this->postJson($url, ['name' => 'Invalid', 'price' => 0, 'member_price' => 1])->assertUnprocessable();
    $tenant->features()->where('feature_key', 'community_event_benefits')->update(['enabled' => false]);
    $this->postJson($url, ['name' => 'Off', 'price' => 0, 'member_price' => 0])->assertUnprocessable();
});

it('rejects lowering a ticket standard price below a retained member price', function (): void {
    [$tenant, , $event] = benefitSettingsManager();
    $type = App\Models\EventTicketType::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'price' => 10000, 'member_price' => 5000]);
    $this->putJson('http://'.eventSubdomainHost($tenant->slug).'/events/'.$event->id.'/ticket-types/'.$type->id, ['name' => $type->name, 'price' => 1000])->assertUnprocessable()->assertJsonValidationErrors('member_price');
});

it('keeps an event currency immutable after member-benefit registrations begin', function (): void {
    [$tenant, , $event] = benefitSettingsManager();
    App\Models\EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'member_benefit_required' => true]);
    $this->putJson('http://'.eventSubdomainHost($tenant->slug).'/events/'.$event->id, [
        'name' => $event->name, 'starts_at' => now()->addDay()->toIso8601String(), 'ends_at' => now()->addDays(2)->toIso8601String(),
        'timezone' => 'Africa/Accra', 'location_type' => 'in_person', 'address' => 'Accra', 'ticket_price' => 0, 'currency' => 'USD', 'status' => 'draft',
    ])->assertUnprocessable()->assertJsonValidationErrors('currency');
});
