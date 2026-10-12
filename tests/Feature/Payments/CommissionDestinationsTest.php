<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\CommissionDestination;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Models\User;
use App\Services\Payment\CommissionDestinations;
use App\Services\Payment\CommissionSplitVerifier;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);

    config(['services.commission_split.account_number' => null]);
    $this->destinations = app(CommissionDestinations::class);
});

function subaccountShape(array $overrides = []): array
{
    return $overrides + ['active' => true, 'currency' => 'GHS', 'settlement_bank' => 'MTN', 'account_number' => '0551234987'];
}

test('with nothing saved and nothing in the environment the split is not enforced', function () {
    expect($this->destinations->isConfigured())->toBeFalse()
        ->and(app(CommissionSplitVerifier::class)->isEnforced())->toBeFalse();
});

test('the environment account is used until one is saved, then it is ignored', function () {
    config(['services.commission_split.account_number' => '0000000001', 'services.commission_split.settlement_bank' => null]);

    expect($this->destinations->accepts(subaccountShape(['account_number' => '0000000001'])))->toBeTrue();

    CommissionDestination::factory()->create(['account_number' => '0551234987']);

    expect($this->destinations->accepts(subaccountShape(['account_number' => '0000000001'])))->toBeFalse()
        ->and($this->destinations->accepts(subaccountShape()))->toBeTrue();
});

test('enforcement is on as soon as an account is saved, even with the environment empty', function () {
    CommissionDestination::factory()->create();

    expect(app(CommissionSplitVerifier::class)->isEnforced())->toBeTrue();
});

test('a subaccount must match the account number, the currency and, when named, the bank', function () {
    CommissionDestination::factory()->create(['currency' => 'GHS', 'settlement_bank' => 'MTN', 'account_number' => '0551234987']);

    expect($this->destinations->accepts(subaccountShape()))->toBeTrue()
        ->and($this->destinations->accepts(subaccountShape(['account_number' => '055 123 4987'])))->toBeTrue()
        ->and($this->destinations->accepts(subaccountShape(['account_number' => '0551234988'])))->toBeFalse()
        ->and($this->destinations->accepts(subaccountShape(['currency' => 'USD'])))->toBeFalse()
        ->and($this->destinations->accepts(subaccountShape(['settlement_bank' => 'VOD'])))->toBeFalse()
        ->and($this->destinations->accepts(subaccountShape(['account_number' => ''])))->toBeFalse();
});

test('an account with no bank named accepts any bank', function () {
    CommissionDestination::factory()->create(['settlement_bank' => null]);

    expect($this->destinations->accepts(subaccountShape(['settlement_bank' => 'VOD'])))->toBeTrue();
});

test('a retiring account is still accepted but no longer shown to organisers, and a retired one is neither', function () {
    CommissionDestination::factory()->create(['label' => 'New', 'account_number' => '0200000002']);
    CommissionDestination::factory()->create(['label' => 'Old', 'account_number' => '0551234987', 'accepted_until' => now()->addDays(10)]);

    expect($this->destinations->accepts(subaccountShape()))->toBeTrue()
        ->and(collect($this->destinations->current())->pluck('label')->all())->toBe(['New']);

    $this->travel(11)->days();

    expect($this->destinations->accepts(subaccountShape()))->toBeFalse();
});

test('the nightly check tells an organiser to move once their account is past its date', function () {
    config(['services.commission_split.account_number' => null]);
    $tenant = Tenant::factory()->create(['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    CommissionDestination::factory()->create(['label' => 'New', 'account_number' => '0200000002']);
    CommissionDestination::factory()->create(['label' => 'Old', 'account_number' => '0551234987', 'accepted_until' => now()->addDays(5)]);
    $gateway = TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'meta' => ['commission_split' => ['subaccount_code' => 'ACCT_old', 'ok' => true, 'reason' => null]],
    ]);
    Http::fake(['api.paystack.co/subaccount/*' => Http::response(['status' => true, 'data' => subaccountShape()])]);

    Artisan::call('payments:verify-commission-splits');
    expect($gateway->fresh()->commissionSplitIsVerified())->toBeTrue();

    $this->travel(6)->days();
    Artisan::call('payments:verify-commission-splits');
    expect($gateway->fresh()->commissionSplitIsVerified())->toBeFalse();
});

/*
 * The admin page.
 */
function destinationAdmin(string $role): User
{
    $user = User::factory()->create(['tenant_id' => null]);
    setPermissionsTeamId(null);
    $user->assignRole(\App\Models\Role::query()->where('name', $role)->whereNull('tenant_id')->firstOrFail());

    return $user;
}

test('the superadmin and platform finance can open the page and add an account', function (string $role) {
    $this->actingAs(destinationAdmin($role))
        ->post(route('admin.billing.commission-destinations.store'), [
            'label' => 'MTN MoMo', 'currency' => 'ghs', 'business_name' => 'MiConvener', 'settlement_bank' => 'MTN', 'account_number' => '055 123 4987',
        ])->assertSessionHas('success');

    $saved = CommissionDestination::query()->firstOrFail();
    expect($saved->currency)->toBe('GHS')
        ->and($saved->account_number)->toBe('055 123 4987')
        ->and(DbValue::raw($saved->getKey()))->not->toContain('4987');

    $this->actingAs(destinationAdmin($role))->get(route('admin.billing.commission-destinations.index'))->assertOk()->assertSee('MTN MoMo');
})->with(['Superadmin', 'Platform Finance']);

final class DbValue
{
    /** The stored (encrypted) form of the account number, to prove it is not kept in the clear. */
    public static function raw(string $id): string
    {
        return (string) \Illuminate\Support\Facades\DB::connection('landlord')->table('commission_destinations')->where('id', $id)->value('account_number');
    }
}

test('an organisation admin cannot manage commission destinations', function () {
    $tenant = Tenant::factory()->create();
    $member = User::factory()->create(['tenant_id' => $tenant->id]);
    setActiveTenantForTest($member);
    $member->assignRole('Org Superadmin');

    $this->actingAs($member)->get(route('admin.billing.commission-destinations.index'))->assertForbidden();
    $this->actingAs($member)->post(route('admin.billing.commission-destinations.store'), ['label' => 'x'])->assertForbidden();
});

test('an account number with letters is refused', function () {
    $this->actingAs(destinationAdmin('Superadmin'))
        ->post(route('admin.billing.commission-destinations.store'), ['label' => 'x', 'currency' => 'GHS', 'business_name' => 'M', 'account_number' => 'abc'])
        ->assertSessionHasErrors('account_number');

    expect(CommissionDestination::count())->toBe(0);
});

test('the last current account cannot be retired, but one with a replacement can', function () {
    $only = CommissionDestination::factory()->create(['label' => 'Only']);
    $admin = destinationAdmin('Platform Finance');

    $this->actingAs($admin)->post(route('admin.billing.commission-destinations.retire', $only->id), ['days' => 30])->assertSessionHas('error');
    expect($only->fresh()->accepted_until)->toBeNull();

    CommissionDestination::factory()->create(['label' => 'Replacement', 'account_number' => '0200000002']);

    $this->actingAs($admin)->post(route('admin.billing.commission-destinations.retire', $only->id), ['days' => 30])->assertSessionHas('success');
    expect($only->fresh()->accepted_until->isFuture())->toBeTrue();
});

test('a retiring account can be made current again, and only a retired one can be removed', function () {
    CommissionDestination::factory()->create(['label' => 'New', 'account_number' => '0200000002']);
    $old = CommissionDestination::factory()->create(['label' => 'Old', 'accepted_until' => now()->addDays(5)]);
    $admin = destinationAdmin('Superadmin');

    $this->actingAs($admin)->delete(route('admin.billing.commission-destinations.destroy', $old->id))->assertSessionHas('error');
    expect(CommissionDestination::count())->toBe(2);

    $this->actingAs($admin)->post(route('admin.billing.commission-destinations.restore', $old->id))->assertSessionHas('success');
    expect($old->fresh()->accepted_until)->toBeNull();

    $old->update(['accepted_until' => now()->subDay()]);
    $this->actingAs($admin)->delete(route('admin.billing.commission-destinations.destroy', $old->id))->assertSessionHas('success');
    expect(CommissionDestination::count())->toBe(1);
});

test('organisers are shown only the current accounts on their payments page', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $tenant->features()->create(['feature_key' => 'paid_tickets', 'enabled' => true]);
    $owner = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $owner->assignRole('Org Superadmin');
    $tenant->users()->attach($owner->id);
    CommissionDestination::factory()->create(['label' => 'MTN', 'account_number' => '0551234987']);
    CommissionDestination::factory()->create(['label' => 'Old', 'account_number' => '0100000001', 'accepted_until' => now()->addDays(5)]);
    $host = 'acme.'.mb_ltrim((string) config('session.domain'), '.');

    $this->actingAs($owner)->get("http://{$host}/settings/payments", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page
            ->where('commissionSplit.enforced', true)
            ->has('commissionSplit.destinations', 1)
            ->where('commissionSplit.destinations.0.account_number', '0551234987'));
});
