<?php

declare(strict_types=1);

use App\Enum\LedgerCategoryEnum;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\TenantPayoutAccount;
use App\Services\Finance\LedgerService;
use App\Services\Marketplace\StoreBalance;
use App\Services\Marketplace\StorePayouts;
use Tests\Support\StoreCheckoutScenario;

it('never treats legacy marketplace credit as merchandise proceeds', function (): void {
    [$tenant] = StoreCheckoutScenario::make();
    app(LedgerService::class)->postTransaction($tenant, null, 'marketplace_booking', 'Legacy balance', 'legacy-store-test', [
        ['code' => LedgerAccount::CODE_GATEWAY_CLEARING, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => 200000],
        ['code' => LedgerAccount::CODE_ORGANIZER_PAYABLE, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => 200000],
    ], category: LedgerCategoryEnum::Marketplace);
    expect(app(StoreBalance::class)->available($tenant))->toBe(0);
});

it('reserves only its own verified account within the isolated commerce balance', function (): void {
    [$tenant, , $actor] = StoreCheckoutScenario::paid();
    setPermissionsTeamId($tenant->id);
    App\Models\Permission::query()->firstOrCreate(['name' => 'manage store finance', 'guard_name' => 'web'], ['category' => 'store']);
    $actor->givePermissionTo('manage store finance');
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'is_verified' => true]);
    $payout = app(StorePayouts::class)->reserve($tenant, $actor, ['amount' => 5000, 'currency' => 'GHS', 'payout_account_id' => $account->id, 'operation_key' => (string) Illuminate\Support\Str::uuid()]);
    expect($payout->category)->toBe(LedgerCategoryEnum::Commerce)->and($payout->event_id)->toBeNull()
        ->and(app(StoreBalance::class)->available($tenant))->toBe(4605);
    $other = TenantPayoutAccount::factory()->create(['is_verified' => true]);
    expect(fn () => app(StorePayouts::class)->reserve($tenant, $actor, ['amount' => 1000, 'currency' => 'GHS', 'payout_account_id' => $other->id, 'operation_key' => (string) Illuminate\Support\Str::uuid()]))->toThrow(Illuminate\Validation\ValidationException::class);
});

it('parks OTP payouts without resending and clears commerce payable once after original transfer verification', function (): void {
    [$tenant, , $actor] = StoreCheckoutScenario::paid();
    setPermissionsTeamId($tenant->id);
    App\Models\Permission::query()->firstOrCreate(['name' => 'manage store finance', 'guard_name' => 'web'], ['category' => 'store']);
    $actor->givePermissionTo('manage store finance');
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'type' => TenantPayoutAccount::TYPE_MOBILE_MONEY, 'recipient_code' => 'RCP_STORE', 'is_verified' => true]);
    $payout = app(StorePayouts::class)->reserve($tenant, $actor, ['amount' => 5000, 'currency' => 'GHS', 'payout_account_id' => $account->id, 'operation_key' => (string) Illuminate\Support\Str::uuid()]);
    Illuminate\Support\Facades\Http::fake(['*api.paystack.co/transfer' => Illuminate\Support\Facades\Http::response(['data' => ['status' => 'otp', 'transfer_code' => 'TRF_STORE', 'fee' => 100]])]);
    app(StorePayouts::class)->send($payout);
    expect($payout->fresh()->status)->toBe(App\Models\EventPayout::STATUS_AWAITING_OTP)
        ->and(app(StorePayouts::class)->send($payout)['http_status'])->toBe(422)
        ->and(App\Models\LedgerTransaction::withoutGlobalScopes()->where('transaction_type', 'payout')->count())->toBe(0);
    $account->update(['account_number_encrypted' => 'changed-bank-after-submission', 'is_verified' => false]);
    Illuminate\Support\Facades\Http::fake(['*api.paystack.co/transfer/finalize_transfer' => Illuminate\Support\Facades\Http::response(['data' => ['status' => 'pending', 'fee' => 100]])]);
    expect(app(StorePayouts::class)->finalize($payout->fresh(), '123456')['http_status'])->toBe(200);
    $reference = $payout->fresh()->provider_reference;
    Illuminate\Support\Facades\Http::fake(['*api.paystack.co/transfer/verify/*' => Illuminate\Support\Facades\Http::response(['data' => ['status' => 'success', 'reference' => $reference, 'transfer_code' => 'TRF_STORE', 'amount' => 4900, 'currency' => 'GHS', 'fee' => 100]])]);
    app(StorePayouts::class)->reconcile($payout->fresh());
    app(StorePayouts::class)->reconcile($payout->fresh());
    expect($payout->fresh()->status)->toBe('paid')->and($payout->fresh()->transfer_fee_amount)->toBe(100)
        ->and(app(StoreBalance::class)->available($tenant))->toBe(4605)
        ->and(App\Models\LedgerTransaction::withoutGlobalScopes()->where('transaction_type', 'payout')->count())->toBe(1);
});

it('keeps buyer refund liabilities out of withdrawable seller cash even when commission was waived', function (): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make();
    App\Models\StoreCommercialAgreement::factory()->create(['shop_id' => $variant->shop_id, 'tenant_id' => $tenant->id, 'version' => 2, 'percentage' => '0.00',
        'account_fingerprint' => hash('sha256', app(App\Services\Giving\GivingGateways::class)->platformIdentity())]);
    Illuminate\Support\Facades\Http::fake(['*api.paystack.co/transaction/initialize' => Illuminate\Support\Facades\Http::response(['data' => ['authorization_url' => 'https://checkout.paystack.com/store-test']])]);
    $order = \Tests\Support\StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Illuminate\Support\Str::uuid());
    $attempt = app(App\Services\Marketplace\StorePayments::class)->start($order, $session, (string) Illuminate\Support\Str::uuid());
    $capture = app(App\Services\Marketplace\StorePaymentEvidence::class)->record($attempt, StoreCheckoutScenario::evidence($attempt));
    app(App\Services\Marketplace\StorePaymentConfirmer::class)->confirm($capture);
    $extraAttempt = App\Models\StorePaymentAttempt::factory()->create(['order_id' => $order->id]);
    $extra = app(App\Services\Marketplace\StorePaymentEvidence::class)->record($extraAttempt, StoreCheckoutScenario::evidence($extraAttempt));
    app(App\Services\Marketplace\StorePaymentConfirmer::class)->confirm($extra);
    expect(app(StoreBalance::class)->available($tenant))->toBe(9610);
});

it('serves the tenant payout lifecycle through protected endpoints and preserves other categories', function (): void {
    [$tenant, , $actor] = StoreCheckoutScenario::paid();
    setPermissionsTeamId($tenant->id);
    App\Models\Permission::query()->firstOrCreate(['name' => 'manage store finance', 'guard_name' => 'web'], ['category' => 'store']);
    $actor->givePermissionTo('manage store finance');
    $actor->tenants()->attach($tenant->id);
    $host = $tenant->slug.'.'.mb_ltrim((string) config('session.domain'), '.');
    $base = 'http://'.$host.'/store/payouts';
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'type' => TenantPayoutAccount::TYPE_MOBILE_MONEY, 'recipient_code' => 'RCP_HTTP_STORE', 'is_verified' => true]);
    $response = $this->actingAs($actor)->postJson($base, ['amount' => 5000, 'currency' => 'GHS', 'payout_account_id' => $account->id, 'operation_key' => (string) Illuminate\Support\Str::uuid()], ['HTTP_HOST' => $host])->assertOk();
    $id = $response->json('payout_id');
    Illuminate\Support\Facades\Http::fake(['*api.paystack.co/transfer' => Illuminate\Support\Facades\Http::response(['data' => ['status' => 'otp', 'transfer_code' => 'TRF_HTTP_STORE', 'fee' => 100]])]);
    $this->postJson($base.'/'.$id.'/send', [], ['HTTP_HOST' => $host])->assertOk()->assertJsonPath('status', 'awaiting_otp');
    Illuminate\Support\Facades\Http::fake(['*api.paystack.co/transfer/finalize_transfer' => Illuminate\Support\Facades\Http::response(['data' => ['status' => 'pending', 'fee' => 100]])]);
    $this->postJson($base.'/'.$id.'/finalize', ['otp' => '123456'], ['HTTP_HOST' => $host])->assertOk();
    $payout = App\Models\EventPayout::withoutGlobalScopes()->findOrFail($id);
    Illuminate\Support\Facades\Http::fake(['*api.paystack.co/transfer/verify/*' => Illuminate\Support\Facades\Http::response(['data' => ['status' => 'success', 'reference' => $payout->provider_reference, 'transfer_code' => 'TRF_HTTP_STORE', 'amount' => 4900, 'currency' => 'GHS', 'fee' => 100]])]);
    $this->postJson($base.'/'.$id.'/reconcile', [], ['HTTP_HOST' => $host])->assertOk()->assertJsonPath('status', 'paid');
    $foreign = App\Models\EventPayout::factory()->create();
    $this->postJson($base.'/'.$foreign->id.'/reconcile', [], ['HTTP_HOST' => $host])->assertNotFound();
});

it('retains a commerce payout destination even when its transfer is parked or terminal', function (string $status): void {
    [$tenant, , $actor] = StoreCheckoutScenario::paid();
    setPermissionsTeamId($tenant->id);
    foreach (['manage store finance', 'manage payment settings'] as $permission) {
        App\Models\Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web'], ['category' => 'store']);
        $actor->givePermissionTo($permission);
    }
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'is_verified' => true]);
    $payout = app(StorePayouts::class)->reserve($tenant, $actor, ['amount' => 5000, 'currency' => 'GHS', 'payout_account_id' => $account->id, 'operation_key' => (string) Illuminate\Support\Str::uuid()]);
    $payout->update(['status' => $status]);
    $actor->tenants()->attach($tenant->id);
    $host = $tenant->slug.'.'.mb_ltrim((string) config('session.domain'), '.');
    $this->actingAs($actor)->deleteJson('http://'.$host.'/payout-accounts/'.$account->id, [], ['HTTP_HOST' => $host])->assertUnprocessable();
    expect($account->fresh())->not->toBeNull();
    expect(fn () => $account->delete())->toThrow(Illuminate\Database\QueryException::class);
})->with(['awaiting_otp', 'failed']);

it('cancels an unsent reservation once to unblock a funded refund without erasing its audit', function (): void {
    [$tenant, $variant, $actor, $session, $order] = StoreCheckoutScenario::paid();
    setPermissionsTeamId($tenant->id);
    App\Models\Permission::query()->firstOrCreate(['name' => 'manage store finance', 'guard_name' => 'web'], ['category' => 'store']);
    $actor->givePermissionTo('manage store finance');
    StoreCheckoutScenario::anotherPaid($tenant, $variant, $session);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'is_verified' => true]);
    $payout = app(StorePayouts::class)->reserve($tenant, $actor, ['amount' => 15000, 'currency' => 'GHS', 'payout_account_id' => $account->id, 'operation_key' => (string) Illuminate\Support\Str::uuid()]);
    Illuminate\Support\Facades\Http::fake();
    $refund = app(App\Services\Marketplace\StoreRefunds::class)->request($order, $actor, (string) Illuminate\Support\Str::uuid(), 'Return');
    expect($refund->status)->toBe('needs_funding');
    $account->update(['is_verified' => false]);
    $cancelled = app(StorePayouts::class)->cancel($payout, $actor);
    $same = app(StorePayouts::class)->cancel($payout, $actor);
    expect($cancelled->status)->toBe('cancelled')->and($same->commerce_cancelled_at)->toEqual($cancelled->commerce_cancelled_at)
        ->and($cancelled->commerce_cancelled_by)->toBe((int) $actor->id)->and($cancelled->provider_reference)->toBeNull()
        ->and(app(StoreBalance::class)->refundBacked($refund))->toBeTrue()
        ->and(app(StorePayouts::class)->send($payout)['http_status'])->toBe(422);
    Illuminate\Support\Facades\Http::assertNothingSent();
    $db = $payout->getConnection();
    expect(fn () => $db->transaction(fn () => $payout->fresh()->update(['status' => 'scheduled'])))->toThrow(Illuminate\Database\QueryException::class);
});

it('never locally cancels a payout after send has claimed the original provider reference', function (): void {
    [$tenant, , $actor] = StoreCheckoutScenario::paid();
    setPermissionsTeamId($tenant->id);
    App\Models\Permission::query()->firstOrCreate(['name' => 'manage store finance', 'guard_name' => 'web'], ['category' => 'store']);
    $actor->givePermissionTo('manage store finance');
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'type' => TenantPayoutAccount::TYPE_MOBILE_MONEY, 'recipient_code' => 'RCP_STORE', 'is_verified' => true]);
    $payout = app(StorePayouts::class)->reserve($tenant, $actor, ['amount' => 5000, 'currency' => 'GHS', 'payout_account_id' => $account->id, 'operation_key' => (string) Illuminate\Support\Str::uuid()]);
    Illuminate\Support\Facades\Http::fake(['*api.paystack.co/transfer' => function () use ($payout, $actor) {
        expect(fn () => app(StorePayouts::class)->cancel($payout, $actor))->toThrow(Illuminate\Validation\ValidationException::class);

        return Illuminate\Support\Facades\Http::response(['data' => ['status' => 'otp', 'transfer_code' => 'TRF_STORE', 'fee' => 100]]);
    }]);
    app(StorePayouts::class)->send($payout);
    expect($payout->fresh()->status)->toBe('awaiting_otp')->and($payout->fresh()->commerce_cancelled_at)->toBeNull();
});

it('authorizes the unsent cancellation endpoint and keeps it usable with checkout disabled', function (): void {
    [$tenant, , $actor] = StoreCheckoutScenario::paid();
    setPermissionsTeamId($tenant->id);
    App\Models\Permission::query()->firstOrCreate(['name' => 'manage store finance', 'guard_name' => 'web'], ['category' => 'store']);
    $actor->givePermissionTo('manage store finance');
    $actor->tenants()->attach($tenant->id);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'is_verified' => true]);
    $payout = app(StorePayouts::class)->reserve($tenant, $actor, ['amount' => 5000, 'currency' => 'GHS', 'payout_account_id' => $account->id, 'operation_key' => (string) Illuminate\Support\Str::uuid()]);
    $tenant->features()->update(['enabled' => false]);
    $host = $tenant->slug.'.'.mb_ltrim((string) config('session.domain'), '.');
    $url = 'http://'.$host.'/store/payouts/'.$payout->id.'/cancel';
    $foreign = App\Models\User::factory()->create();
    expect(fn () => app(StorePayouts::class)->cancel($payout, $foreign))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
    $this->actingAs($actor)->postJson($url, [], ['HTTP_HOST' => $host])->assertOk()->assertJsonPath('status', 'cancelled');
    $this->postJson($url, [], ['HTTP_HOST' => $host])->assertOk();
    $actor->revokePermissionTo('manage store finance');
    $this->postJson($url, [], ['HTTP_HOST' => $host])->assertForbidden();
});
