<?php

declare(strict_types=1);

use App\Models\GivingFund;
use App\Models\GivingGift;
use Tests\Support\GivingScenario;

function givingPageHost($tenant): string
{
    return 'http://'.eventSubdomainHost($tenant->slug);
}

test('no dues giving manager has a usable giving page without private donor props', function () {
    [$tenant, $user, $fund] = GivingScenario::make();
    GivingGift::factory()->create(['tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'payer_email' => 'private-giver@example.test']);
    $this->actingAs($user)->get(givingPageHost($tenant).'/community/money/giving')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Tenant/Community/MoneyGiving')->has('funds', 1)->where('canReadFinance', true));
    $user->revokePermissionTo('read finance');
    $this->get(givingPageHost($tenant).'/community/money/giving')->assertOk()->assertDontSee('private-giver@example.test')->assertInertia(fn ($page) => $page->where('report', null)->where('gifts', []));
    expect(App\Models\Member::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('public listing excludes unlisted private draft archived and event funds', function () {
    [$tenant,, $fund] = GivingScenario::make(['visibility' => 'public', 'progress' => 'hidden']);
    foreach ([['visibility' => 'unlisted'], ['visibility' => 'private'], ['state' => 'draft'], ['state' => 'archived']] as $attrs) {
        GivingFund::factory()->create(['tenant_id' => $tenant->id, 'state' => 'open', 'visibility' => 'public', ...$attrs]);
    }
    $this->get(givingPageHost($tenant).'/give')->assertOk()->assertInertia(fn ($page) => $page->has('funds', 1));
    $this->get(givingPageHost($tenant).'/give/'.$fund->slug)->assertOk()->assertInertia(fn ($page) => $page->where('fund.progress', null)->where('providers', []));
    $fund->update(['visibility' => 'unlisted', 'progress' => 'percentage', 'kind' => 'campaign', 'target' => 10000]);
    $this->get(givingPageHost($tenant).'/give/'.$fund->slug)->assertOk()->assertInertia(fn ($page) => $page->has('fund.progress.percent')->missing('fund.progress.raised')->missing('fund.target'));
    $fund->update(['visibility' => 'private']);
    $this->get(givingPageHost($tenant).'/give/'.$fund->slug)->assertNotFound();
});

test('public funds hide contacts and safely display closed and unavailable online states', function () {
    [$tenant,, $fund] = GivingScenario::make(['visibility' => 'public', 'state' => 'closed']);
    GivingGift::factory()->create(['tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'payer_email' => 'private-giver@example.test']);
    $this->get(givingPageHost($tenant).'/give/'.$fund->slug)->assertOk()->assertDontSee('private-giver@example.test')->assertInertia(fn ($page) => $page->where('accepting', false));
    $tenant->features()->where('feature_key', 'community_giving')->update(['enabled' => false]);
    $this->get(givingPageHost($tenant).'/give/'.$fund->slug)->assertNotFound();
});

test('church and volunteer fund screens preserve privacy across anonymous cash and named gifts', function () {
    [$tenant,$user,$fund] = GivingScenario::make(['visibility' => 'public', 'progress' => 'amounts']);
    $host = givingPageHost($tenant);
    $this->actingAs($user)->postJson($host.'/community/money/giving/funds/'.$fund->id.'/gifts', ['submission_key' => (string) Illuminate\Support\Str::uuid(), 'amount' => 5000, 'currency' => 'GHS', 'source' => 'bulk_cash'])->assertCreated();
    GivingGift::factory()->create(['tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'is_anonymous' => true, 'payer_name' => 'Do not publish this name', 'payer_email' => 'private-giver@example.test', 'status' => 'completed']);
    $this->get($host.'/community/money/giving')->assertOk()->assertDontSee('Do not publish this name')->assertDontSee('private-giver@example.test');
    $this->get($host.'/give/'.$fund->slug)->assertOk()->assertDontSee('Do not publish this name')->assertDontSee('private-giver@example.test')->assertInertia(fn ($page) => $page->has('fund.progress.raised'));
    expect(App\Models\DuesCharge::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0)->and(App\Models\MembershipType::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('giving only finance does not default to empty dues or ticket cards', function () {
    [$tenant,$user] = GivingScenario::make();
    $tenant->update(['package_id' => App\Models\Package::factory()->create()->id]);
    $tenant->features()->updateOrCreate(['feature_key' => 'paid_tickets'], ['enabled' => false]);
    $this->actingAs($user)->get(givingPageHost($tenant).'/finance')->assertOk()->assertInertia(fn ($page) => $page->where('givingOnly', true)->where('givingReportUrl', fn ($url) => str_contains($url, 'giving/report')));
});

test('give pages link to the central private contribution history on the platform host', function () {
    [$tenant,,$fund] = GivingScenario::make(['visibility' => 'public']);
    $host = eventSubdomainHost($tenant->slug);
    $expected = mb_rtrim((string) config('app.url'), '/').'/my?organiser='.$tenant->slug.'&section=money&tab=giving';
    $this->withServerVariables(['HTTP_HOST' => $host])->get('http://'.$host.'/give/'.$fund->slug)->assertInertia(fn ($page) => $page->where('historyUrl', $expected));
});
