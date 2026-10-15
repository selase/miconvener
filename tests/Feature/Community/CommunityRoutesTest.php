<?php

declare(strict_types=1);

use App\Enum\LedgerCategoryEnum;
use App\Models\DuesCharge;
use App\Models\EventPayout;
use App\Models\Member;
use App\Models\Tenant;
use App\Models\TenantPayoutAccount;
use App\Models\User;
use App\Services\Finance\LedgerService;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

/**
 * @return array{0: Tenant, 1: User}
 */
function communityRoutesTenant(string $slug = 'acme', bool $enabled = true): array
{
    [$tenant, $user] = eventHost($slug);

    if ($enabled) {
        Artisan::call('community:enable', ['tenant' => $slug]);
    }

    return [$tenant->fresh(), $user];
}

/**
 * A full URL on the tenant's subdomain. A bare path in a test request goes to
 * the app's main host and never reaches a subdomain route.
 */
function communityUrl(string $path, string $slug = 'acme'): string
{
    return 'http://'.eventSubdomainHost($slug).$path;
}

function communityConsole(User $user, string $slug = 'acme'): Illuminate\Foundation\Testing\TestCase
{
    return test()->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost($slug)]);
}

test('every community and payouts page renders for an enabled organisation', function () {
    [$tenant, $user] = communityRoutesTenant();
    Member::factory()->create(['tenant_id' => $tenant->id]);

    communityConsole($user)->get(route('tenant.community.overview', ['subdomain' => 'acme']))
        ->assertInertia(fn (Assert $page) => $page->component('Tenant/Community/Overview')->where('counts.members', 1));
    communityConsole($user)->get(route('tenant.community.people.index', ['subdomain' => 'acme']))
        ->assertInertia(fn (Assert $page) => $page->component('Tenant/Community/People')->has('members.data', 1));
    communityConsole($user)->get(route('tenant.community.money.dues.index', ['subdomain' => 'acme']))
        ->assertInertia(fn (Assert $page) => $page->component('Tenant/Community/MoneyDues')->has('membershipTypes', 1));
    communityConsole($user)->get(route('tenant.finance.payouts.index', ['subdomain' => 'acme']))
        ->assertInertia(fn (Assert $page) => $page->component('Tenant/Finance/Payouts'));
});

test('every community URL is a 404 while the switch is off', function () {
    [, $user] = communityRoutesTenant(enabled: false);

    foreach (['/community', '/community/people', '/community/money/dues', '/finance/payouts'] as $path) {
        communityConsole($user)->get(communityUrl($path))->assertNotFound();
    }
});

test('payouts stay reachable for a tenant with dues money after the switch is turned off', function () {
    [$tenant, $user] = communityRoutesTenant();
    app(LedgerService::class)->ensureAccountsExist($tenant, null, 'GHS', LedgerCategoryEnum::Dues);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'is_verified' => true]);
    EventPayout::factory()->create([
        'tenant_id' => $tenant->id, 'event_id' => null, 'category' => LedgerCategoryEnum::Dues,
        'payout_account_id' => $account->id, 'amount' => 900, 'currency' => 'GHS', 'status' => EventPayout::STATUS_AWAITING_OTP,
    ]);
    Artisan::call('community:enable', ['tenant' => 'acme', '--off' => true]);

    communityConsole($user)->get(route('tenant.finance.payouts.index', ['subdomain' => 'acme']))->assertOk();
    communityConsole($user)->get(communityUrl('/community/people'))->assertNotFound();
});

test('old console URLs redirect permanently to their new homes', function () {
    [, $user] = communityRoutesTenant();

    communityConsole($user)->get(communityUrl('/dues/members'))->assertStatus(301)->assertRedirect(communityUrl('/community/people'));
    communityConsole($user)->get(communityUrl('/dues/classes'))->assertStatus(301)->assertRedirect(communityUrl('/community/money/dues'));
    communityConsole($user)->get(communityUrl('/dues/payouts'))->assertStatus(301)->assertRedirect(communityUrl('/finance/payouts'));
});

test('a pay link issued before the move still opens and shows its receipt', function () {
    [$tenant] = communityRoutesTenant();
    $member = Member::factory()->create(['tenant_id' => $tenant->id]);
    DuesCharge::factory()->create(['tenant_id' => $tenant->id, 'member_id' => $member->id, 'membership_type_id' => $member->membership_type_id]);

    $this->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])->get(communityUrl('/pay/'.$member->pay_token))
        ->assertInertia(fn (Assert $page) => $page->component('Public/MemberPay')->has('charges', 1));
    $this->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])->get(communityUrl('/pay/'.$member->pay_token.'/receipt'))->assertOk();
});

test('the overview counts only this organisation', function () {
    [$tenant, $user] = communityRoutesTenant('acme');
    [$other] = communityRoutesTenant('beta');
    Member::factory()->count(2)->create(['tenant_id' => $tenant->id]);
    Member::factory()->count(3)->create(['tenant_id' => $other->id]);

    communityConsole($user)->get(route('tenant.community.overview', ['subdomain' => 'acme']))
        ->assertInertia(fn (Assert $page) => $page->where('counts.members', 2));
});
