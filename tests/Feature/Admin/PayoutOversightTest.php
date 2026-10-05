<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventLedgerEntry;
use App\Models\EventPayout;
use App\Models\TenantPayoutAccount;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

/**
 * Payouts and refunds across organisations. Payouts go through MiConvener's
 * Paystack account, so the one-time code reaches us; a superadmin releases
 * the payout from the console instead of passing the code to the organiser.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    config(['services.settlement.paystack.secret_key' => 'sk_settlement_test_123']);

    $this->superadmin = User::factory()->create(['tenant_id' => null]);
    setPermissionsTeamId(null);
    $this->superadmin->assignRole(Role::query()->where('name', 'Superadmin')->whereNull('tenant_id')->firstOrFail());
});

/**
 * @return array{0: App\Models\Tenant, 1: User, 2: Event, 3: EventPayout}
 */
function oversightPayout(string $status, array $attributes = []): array
{
    [$tenant, $user] = eventHost('acme-'.uniqid());
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Health Summit', 'currency' => 'GHS']);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'type' => TenantPayoutAccount::TYPE_MOBILE_MONEY, 'recipient_code' => 'RCP_1']);
    $payout = EventPayout::factory()->create($attributes + [
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'payout_account_id' => $account->id,
        'amount' => 700000,
        'status' => $status,
    ]);

    return [$tenant, $user, $event, $payout];
}

test('payouts waiting for a code, failed or stuck are listed for attention', function (): void {
    oversightPayout(EventPayout::STATUS_AWAITING_OTP, ['provider_transfer_code' => 'TRF_1']);
    oversightPayout(EventPayout::STATUS_FAILED, ['failure_reason' => 'Account name mismatch']);
    [, , , $stuck] = oversightPayout(EventPayout::STATUS_PROCESSING);
    EventPayout::query()->withoutGlobalScopes()->whereKey($stuck->id)->update(['updated_at' => now()->subDays(2)]);
    oversightPayout(EventPayout::STATUS_PAID, ['paid_at' => now()]);

    $this->actingAs($this->superadmin)
        ->get(route('admin.billing.payouts.index'))
        ->assertOk()
        ->assertSeeInOrder(['Need attention', '3'])
        ->assertSee('awaiting otp')
        ->assertSee('Account name mismatch')
        ->assertSee('GHS 7,000.00');
});

test('a superadmin releases a payout with the code Paystack sent to MiConvener', function (): void {
    [, , , $payout] = oversightPayout(EventPayout::STATUS_AWAITING_OTP, ['provider_transfer_code' => 'TRF_otp_1']);
    Http::fake(['api.paystack.co/transfer/finalize_transfer' => Http::response(['status' => true, 'data' => ['status' => 'success']])]);

    $this->actingAs($this->superadmin)
        ->post(route('admin.billing.payouts.release', $payout->id), ['otp' => '123456'])
        ->assertSessionHas('success', 'Payout released.');

    $payout = EventPayout::query()->withoutGlobalScopes()->findOrFail($payout->id);

    expect($payout->status)->toBe(EventPayout::STATUS_PROCESSING)
        ->and($payout->net_paid_amount)->toBe(699900);
    Http::assertSent(fn ($request): bool => $request['otp'] === '123456' && $request['transfer_code'] === 'TRF_otp_1');
    $this->assertDatabaseHas('activity_log', ['description' => 'Released payout with one-time code'], 'landlord');
});

test('a wrong code keeps the payout waiting so it can be tried again', function (): void {
    [, , , $payout] = oversightPayout(EventPayout::STATUS_AWAITING_OTP, ['provider_transfer_code' => 'TRF_otp_1']);
    Http::fake(['api.paystack.co/transfer/finalize_transfer' => Http::response(['status' => false, 'message' => 'Invalid OTP'], 400)]);

    $this->actingAs($this->superadmin)
        ->post(route('admin.billing.payouts.release', $payout->id), ['otp' => '000000'])
        ->assertSessionHas('error');

    expect(EventPayout::query()->withoutGlobalScopes()->findOrFail($payout->id)->status)->toBe(EventPayout::STATUS_AWAITING_OTP);
});

test('refunds across organisations are listed with the last 30 days total', function (): void {
    [$tenant, , $event] = oversightPayout(EventPayout::STATUS_PAID);
    EventLedgerEntry::query()->withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'type' => EventLedgerEntry::TYPE_REFUND,
        'gross_amount' => -25000,
        'gateway_fee_amount' => 0,
        'commission_amount' => 0,
        'net_amount' => -25000,
        'currency' => 'GHS',
        'provider' => 'paystack',
        'provider_reference' => 'REF_1',
    ]);

    $this->actingAs($this->superadmin)
        ->get(route('admin.billing.payouts.index'))
        ->assertOk()
        ->assertSee('GHS 250.00')
        ->assertSee('Health Summit');
});

test('organisation admins cannot reach payouts oversight or release payouts', function (): void {
    [, $user, , $payout] = oversightPayout(EventPayout::STATUS_AWAITING_OTP, ['provider_transfer_code' => 'TRF_1']);

    $this->actingAs($user)->get(route('admin.billing.payouts.index'))->assertForbidden();
    $this->actingAs($user)->post(route('admin.billing.payouts.release', $payout->id), ['otp' => '1'])->assertForbidden();
});
