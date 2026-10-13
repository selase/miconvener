<?php

declare(strict_types=1);

use App\Enum\DuesChargeStatusEnum;
use App\Models\DuesCharge;
use App\Models\DuesClass;
use App\Models\Member;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

/**
 * @return array{0: Tenant, 1: User, 2: DuesClass, 3: string}
 */
function duesPagesFixture(string $slug = 'acme'): array
{
    [$tenant, $user] = eventHost($slug);
    Artisan::call('dues:enable', ['tenant' => $tenant->slug]);
    $class = DuesClass::factory()->create(['tenant_id' => $tenant->id, 'amount' => 5000]);

    return [$tenant->fresh(), $user, $class, eventSubdomainHost($slug)];
}

test('the members page sends the members, the active classes and each member\'s pay link and charges', function () {
    [$tenant, $user, $class, $host] = duesPagesFixture();
    DuesClass::factory()->create(['tenant_id' => $tenant->id, 'is_active' => false]);
    $members = Member::factory()->count(2)->create(['tenant_id' => $tenant->id, 'dues_class_id' => $class->id]);
    $first = $members->sortBy('name')->first();
    DuesCharge::factory()->create(['tenant_id' => $tenant->id, 'member_id' => $first->id, 'dues_class_id' => $class->id, 'amount' => 5000]);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => $host])
        ->get(route('tenant.dues.members.index', ['subdomain' => 'acme']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/Dues/Members')
            ->has('members.data', 2)
            ->has('classes', 1)
            ->where('classes.0.id', $class->id)
            ->where('members.data.0.id', $first->id)
            ->has('members.data.0.charges', 1)
            ->where('members.data.0.charges.0.status', 'open')
            ->where('members.data.0.pay_url', route('public.dues.show', ['subdomain' => 'acme', 'token' => $first->pay_token]))
            ->missing('members.data.0.pay_token'));
});

test('a member\'s pay link opens that member\'s own pay page', function () {
    [$tenant, $user, $class, $host] = duesPagesFixture();
    $member = Member::factory()->create(['tenant_id' => $tenant->id, 'dues_class_id' => $class->id]);

    $page = $this->actingAs($user)->withServerVariables(['HTTP_HOST' => $host])
        ->get(route('tenant.dues.members.index', ['subdomain' => 'acme']))
        ->viewData('page');
    $payUrl = $page['props']['members']['data'][0]['pay_url'];

    expect($payUrl)->toEndWith('/pay/'.$member->pay_token);
});

test('the members, classes and payouts pages are 404 while dues are off', function (string $routeName) {
    [$tenant, $user, , $host] = duesPagesFixture();
    $tenant->features()->where('feature_key', 'member_dues')->update(['enabled' => false]);

    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => $host])
        ->get(route($routeName, ['subdomain' => 'acme']))
        ->assertNotFound();
})->with(['tenant.dues.members.index', 'tenant.dues.classes.index', 'tenant.dues.payouts.index']);

test('the pay page props hold only that member\'s open charges', function () {
    [$tenant, , $class, $host] = duesPagesFixture();
    $member = Member::factory()->create(['tenant_id' => $tenant->id, 'dues_class_id' => $class->id, 'name' => 'Ama Mensah']);
    $other = Member::factory()->create(['tenant_id' => $tenant->id, 'dues_class_id' => $class->id]);
    $mine = DuesCharge::factory()->create(['tenant_id' => $tenant->id, 'member_id' => $member->id, 'dues_class_id' => $class->id, 'amount' => 5000]);
    DuesCharge::factory()->create(['tenant_id' => $tenant->id, 'member_id' => $member->id, 'dues_class_id' => $class->id, 'status' => DuesChargeStatusEnum::Paid, 'period_start' => now()->subMonths(2)->startOfMonth()->toDateString()]);
    DuesCharge::factory()->create(['tenant_id' => $tenant->id, 'member_id' => $other->id, 'dues_class_id' => $class->id]);

    $this->get("http://{$host}/pay/{$member->pay_token}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/MemberPay')
            ->where('member.first_name', 'Ama')
            ->has('charges', 1)
            ->where('charges.0.id', $mine->id));
});

test('member_dues is a shared feature only while dues are enabled', function () {
    [$tenant, $user, , $host] = duesPagesFixture();

    $this->actingAs($user)->get("http://{$host}/settings/webhooks", ['HTTP_HOST' => $host])
        ->assertInertia(fn (Assert $page) => $page->where('tenant.features.member_dues', true));

    $tenant->features()->where('feature_key', 'member_dues')->update(['enabled' => false]);

    $this->actingAs($user)->get("http://{$host}/settings/webhooks", ['HTTP_HOST' => $host])
        ->assertInertia(fn (Assert $page) => $page->where('tenant.features.member_dues', false));
});

test('the info flash from the pay callback is shared so the pay page can show it', function () {
    [$tenant, , $class, $host] = duesPagesFixture();
    $member = Member::factory()->create(['tenant_id' => $tenant->id, 'dues_class_id' => $class->id]);

    $this->withSession(['info' => 'We are finalising your payment.'])
        ->get("http://{$host}/pay/{$member->pay_token}")
        ->assertInertia(fn (Assert $page) => $page->where('flash.info', 'We are finalising your payment.')->etc());
});
