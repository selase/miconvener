<?php

declare(strict_types=1);

use App\Models\DuesCharge;
use App\Models\GivingFund;
use App\Models\GivingGift;
use App\Models\Member;
use App\Models\MembershipType;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function givingManager(): array
{
    [$tenant] = eventHost('giving');
    $tenant->features()->updateOrCreate(['feature_key' => 'community'], ['enabled' => true]);
    $tenant->features()->updateOrCreate(['feature_key' => 'community_giving'], ['enabled' => true]);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->givePermissionTo(['manage giving', 'read finance']);
    $tenant->users()->attach($user->id);
    test()->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('giving')]);

    return [$tenant, $user];
}

function givingUrl(string $suffix = ''): string
{
    return 'http://'.eventSubdomainHost('giving').'/community/money/giving/funds'.$suffix;
}

test('a giving-only organisation needs no membership setup', function () {
    [$tenant] = givingManager();
    $this->postJson(givingUrl(), ['name' => 'Community projects', 'slug' => 'projects', 'currency' => 'GHS', 'kind' => 'open', 'state' => 'open'])
        ->assertCreated()->assertJsonPath('fund.name', 'Community projects');
    expect(Member::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and(MembershipType::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and(DuesCharge::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
    $fund = GivingFund::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
    $this->postJson(givingUrl('/'.$fund->id.'/close'), ['reason' => 'Campaign complete'])->assertOk()->assertJsonPath('fund.state', 'closed');
    $this->postJson(givingUrl('/'.$fund->id.'/reopen'), ['reason' => 'New season'])->assertOk()->assertJsonPath('fund.state', 'open');
    $this->postJson(givingUrl('/'.$fund->id.'/archive'), ['reason' => 'Retired'])->assertOk()->assertJsonPath('fund.state', 'archived');
});

test('fund creation rejects duplicate slugs and invalid campaigns', function () {
    [$tenant] = givingManager();
    GivingFund::factory()->create(['tenant_id' => $tenant->id, 'slug' => 'projects']);
    $this->postJson(givingUrl(), ['name' => 'Again', 'slug' => 'projects', 'currency' => 'GHS', 'kind' => 'open'])->assertUnprocessable()->assertJsonValidationErrors('slug');
    $this->postJson(givingUrl(), ['name' => 'Campaign', 'slug' => 'campaign', 'currency' => 'GHS', 'kind' => 'campaign', 'target' => 0])->assertUnprocessable()->assertJsonValidationErrors('target');
});

test('fund mutations reject foreign tenants disabled rollout and missing permission', function () {
    [$tenant, $user] = givingManager();
    $foreign = GivingFund::factory()->create();
    $this->putJson(givingUrl('/'.$foreign->id), ['name' => 'Stolen'])->assertNotFound();
    $tenant->features()->where('feature_key', 'community_giving')->update(['enabled' => false]);
    $this->postJson(givingUrl(), ['name' => 'Hidden'])->assertNotFound();
    $tenant->features()->where('feature_key', 'community_giving')->update(['enabled' => true]);
    $user->revokePermissionTo('manage giving');
    $this->postJson(givingUrl(), ['name' => 'Forbidden'])->assertForbidden();
});

test('history locks a funds currency and event binding', function () {
    [$tenant] = givingManager();
    $fund = GivingFund::factory()->create(['tenant_id' => $tenant->id]);
    GivingGift::factory()->create(['tenant_id' => $tenant->id, 'fund_id' => $fund->id]);
    $this->putJson(givingUrl('/'.$fund->id), ['name' => $fund->name, 'slug' => $fund->slug, 'kind' => 'open', 'currency' => 'USD'])->assertUnprocessable()->assertJsonValidationErrors('currency');
});

test('giving-only staff can open overview without people data and payment settings including stripe', function () {
    [$tenant, $user] = givingManager();
    $tenant->features()->updateOrCreate(['feature_key' => 'paid_tickets'], ['enabled' => false]);
    $this->get('http://'.eventSubdomainHost('giving').'/community')->assertOk()->assertInertia(fn ($page) => $page->where('counts', null)->where('reviewCounts', null)->where('membershipTypes', []));
    $user->givePermissionTo('manage payment settings');
    $this->get('http://'.eventSubdomainHost('giving').'/settings/payments')->assertOk()->assertInertia(fn ($page) => $page->where('givingPaymentsAvailable', true)->where('ticketPaymentsAvailable', false));
    $this->get('http://'.eventSubdomainHost('giving').'/settings/payments/stripe/connect')->assertRedirect();
});

test('an optional event binding stays private and cannot cross organisations', function () {
    [$tenant] = givingManager();
    $event = App\Models\Event::factory()->create(['tenant_id' => $tenant->id]);
    $data = ['name' => 'Event support', 'slug' => 'event-support', 'kind' => 'open', 'currency' => 'GHS', 'event_id' => $event->id, 'visibility' => 'public'];
    $this->postJson(givingUrl(), $data)->assertCreated()->assertJsonPath('fund.visibility', 'private');
    $foreign = App\Models\Event::factory()->create();
    $this->postJson(givingUrl(), [...$data, 'slug' => 'foreign', 'event_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('event_id');
});

test('staff from another organisation can use public Giving without gaining console or receipt access', function () {
    [$home, $actor] = Tests\Support\GivingScenario::make();
    $tenant = App\Models\Tenant::factory()->create();
    $tenant->features()->create(['feature_key' => 'community', 'enabled' => true]);
    $tenant->features()->create(['feature_key' => 'community_giving', 'enabled' => true]);
    $fund = GivingFund::factory()->create(['tenant_id' => $tenant->id, 'state' => 'open', 'visibility' => 'public']);
    $private = GivingFund::factory()->create(['tenant_id' => $tenant->id, 'state' => 'open', 'visibility' => 'private']);
    $gift = GivingGift::factory()->create(['tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'status' => 'completed', 'payer_email' => 'private-recipient@example.test']);
    $attempt = App\Models\GivingPaymentAttempt::factory()->create(['tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'gift_id' => $gift->id, 'state' => 'confirmed']);
    $host = 'http://'.eventSubdomainHost($tenant->slug);
    $proof = ['email' => 'independent-giver@example.test', 'expires_at' => now()->addHour()->timestamp];
    $this->actingAs($actor)->withSession([App\Services\Events\PlatformAttendeeVerification::SESSION_KEY => $proof])->get($host.'/give')->assertOk()->assertInertia(fn ($page) => $page->has('funds', 1));
    $this->get($host.'/give/'.$fund->slug)->assertOk()->assertDontSee('private-recipient@example.test');
    $this->get($host.'/give/'.$private->slug)->assertNotFound();
    $this->get($host.'/giving/payments/'.$attempt->reference.'/receipt')->assertNotFound();
    $this->assertAuthenticatedAs($actor);
    $this->assertEquals($proof, session(App\Services\Events\PlatformAttendeeVerification::SESSION_KEY));
    $this->getJson($host.'/community/money/giving')->assertForbidden();
    $this->assertAuthenticatedAs($actor);
    expect($actor->tenants()->where('tenants.id', $tenant->id)->exists())->toBeFalse();
});
