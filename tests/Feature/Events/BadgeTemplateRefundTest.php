<?php

declare(strict_types=1);

use App\Models\BadgeTemplatePurchase;
use App\Models\LedgerAccount;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StorePaymentAttempt;
use App\Models\StorePaymentObservation;
use App\Models\User;
use App\Services\Badges\BadgeTemplateRefunds;
use App\Services\Finance\LedgerService;
use App\Services\Marketplace\StorePaymentConfirmer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    refreshTenantDatabases();
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake([]);
    config(['services.settlement.paystack.secret_key' => 'sk_test_badge_refunds']);
    $fingerprint = hash('sha256', app(App\Services\Giving\GivingGateways::class)->platformIdentity());
    $this->purchase = BadgeTemplatePurchase::factory()->create(['order_id' => App\Models\StoreOrder::factory()->badgeTemplate()->state(['account_fingerprint' => $fingerprint])]);
    $this->purchase->order->update(['accepted_at' => now()]);
    $this->attempt = StorePaymentAttempt::factory()->create(['order_id' => $this->purchase->order_id]);
    $this->capture = StorePaymentObservation::factory()->create(['attempt_id' => $this->attempt->id, 'gateway_fee_pesewas' => 100]);
    app(StorePaymentConfirmer::class)->confirm($this->capture);
    $this->actor = User::factory()->create();
    setPermissionsTeamId(null);
    $permission = Permission::query()->firstOrCreate(['name' => 'manage template refunds', 'guard_name' => 'web'], ['category' => 'platform']);
    $role = Role::query()->create(['name' => 'Template Finance', 'guard_name' => 'web', 'tenant_id' => null]);
    $role->givePermissionTo($permission);
    $this->actor->assignRole($role);
});

test('an unused licence refund suspends output while its reserve needs funding', function (): void {
    $refund = app(BadgeTemplateRefunds::class)->request($this->purchase, $this->actor, (string) Str::uuid(), 'No longer required');
    expect($refund->amount_pesewas)->toBe(5000)->and($refund->status)->toBe('needs_funding')
        ->and($this->purchase->licence->fresh()->state)->toBe('suspended')->and($refund->seller_debit_pesewas)->toBe(0);
    Http::assertNothingSent();
});

test('the ordinary refund deadline is exclusive at seven calendar days', function (): void {
    $this->travel(7)->days();
    expect(fn () => app(BadgeTemplateRefunds::class)->request($this->purchase, $this->actor, (string) Str::uuid(), 'Late request'))->toThrow(ValidationException::class);
});

test('a used licence needs an explicit audited platform exception', function (): void {
    $this->purchase->licence->update(['first_used_at' => now()]);
    expect(fn () => app(BadgeTemplateRefunds::class)->request($this->purchase, $this->actor, (string) Str::uuid(), 'Used design'))->toThrow(ValidationException::class);
    $refund = app(BadgeTemplateRefunds::class)->request($this->purchase, $this->actor, (string) Str::uuid(), 'Verified rendering failure', true);
    expect($refund->status)->toBe('needs_funding')->and($this->purchase->licence->fresh()->state)->toBe('suspended');
    expect(App\Models\BadgeTemplateLicenceEvent::query()->where('action', 'refund_approved')->firstOrFail()->evidence['exception'])->toBeTrue();
});

test('a tenant permission of the same name never authorises platform refunds', function (): void {
    $actor = User::factory()->create(['tenant_id' => $this->purchase->buyer_tenant_id]);
    setPermissionsTeamId($this->purchase->buyer_tenant_id);
    $actor->givePermissionTo('manage template refunds');
    expect(fn () => app(BadgeTemplateRefunds::class)->request($this->purchase, $actor, (string) Str::uuid(), 'Unauthorised'))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
});

test('repeating an approved refund returns its original identity', function (): void {
    $key = (string) Str::uuid();
    $service = app(BadgeTemplateRefunds::class);
    $first = $service->request($this->purchase, $this->actor, $key, 'Duplicate click');
    expect($service->request($this->purchase, $this->actor, $key, 'Duplicate click')->id)->toBe($first->id)
        ->and(App\Models\StoreRefund::query()->where('order_id', $this->purchase->order_id)->count())->toBe(1);
});

test('a verified full refund revokes the licence and retains the original processing expense', function (): void {
    app(LedgerService::class)->postTransaction($this->purchase->order->tenant, null, 'badge_template_reserve_funding', 'Test reserve funding', 'refund-fixture', [
        ['code' => '1020', 'direction' => 'debit', 'amount' => 10000], ['code' => '1010', 'direction' => 'credit', 'amount' => 10000],
    ], null, 'GHS', App\Enum\LedgerCategoryEnum::Commerce);
    Http::fake(['*api.paystack.co/refund' => Http::response(['data' => ['id' => 123, 'status' => 'pending']])]);
    $refund = app(BadgeTemplateRefunds::class)->request($this->purchase, $this->actor, (string) Str::uuid(), 'Unused purchase');
    Http::fake([
        '*api.paystack.co/refund/123' => Http::response(['data' => ['id' => 123, 'status' => 'processed', 'amount' => 5000, 'currency' => 'GHS', 'merchant_note' => 'store:'.$refund->operation_key, 'transaction' => ['id' => 987]]]),
        '*api.paystack.co/transaction/verify/*' => Http::response(['data' => ['id' => 987, 'reference' => $this->attempt->reference, 'status' => 'success', 'amount' => 5000, 'fees' => 100, 'currency' => 'GHS']]),
    ]);
    app(App\Services\Marketplace\StoreRefunds::class)->reconcile($refund);
    expect($refund->fresh()->status)->toBe('confirmed')->and($this->purchase->fresh()->state)->toBe('refunded')
        ->and($this->purchase->licence->fresh()->state)->toBe('refunded');
    $fee = LedgerAccount::withoutGlobalScopes()->where('tenant_id', $this->purchase->order->tenant_id)->where('category', 'commerce')->where('code', '5000')->firstOrFail();
    expect($fee->currentBalance())->toBe(100);
});

test('unknown refund submission remains suspended and is never blindly sent again', function (): void {
    app(LedgerService::class)->postTransaction($this->purchase->order->tenant, null, 'badge_template_reserve_funding', 'Test funding', 'unknown-funding', [
        ['code' => '1020', 'direction' => 'debit', 'amount' => 10000], ['code' => '1010', 'direction' => 'credit', 'amount' => 10000],
    ], null, 'GHS', App\Enum\LedgerCategoryEnum::Commerce);
    $calls = 0;
    Http::fake(['*api.paystack.co/refund' => function () use (&$calls) {
        $calls++;
        throw new RuntimeException('timeout');
    }]);
    $key = (string) Str::uuid();
    $service = app(BadgeTemplateRefunds::class);
    $refund = $service->request($this->purchase, $this->actor, $key, 'Unknown response');
    expect($refund->status)->toBe('unknown')->and($this->purchase->licence->fresh()->state)->toBe('suspended');
    $service->request($this->purchase, $this->actor, $key, 'Unknown response');
    expect($calls)->toBe(1);
});

test('refunding extra captured money preserves the valid paid licence', function (): void {
    $attempt = StorePaymentAttempt::factory()->create(['order_id' => $this->purchase->order_id, 'status' => 'failed']);
    $extra = StorePaymentObservation::factory()->create(['attempt_id' => $attempt->id]);
    app(StorePaymentConfirmer::class)->confirm($extra);
    $refund = app(App\Services\Marketplace\StoreRefunds::class)->request($this->purchase->order, $this->actor, (string) Str::uuid(), 'Extra capture', $extra->id);
    expect($refund->status)->toBe('needs_funding')->and($this->purchase->licence->fresh()->state)->toBe('active')
        ->and($this->purchase->fresh()->state)->toBe('paid');
});

test('calendar-day refunds retain the frozen organisation timezone across daylight saving', function (): void {
    $purchase = new BadgeTemplatePurchase(['terms_snapshot' => ['licence_timezone' => 'America/New_York']]);
    $purchase->setRelation('order', new App\Models\StoreOrder(['paid_at' => '2026-10-28 14:00:00+00']));
    expect(app(BadgeTemplateRefunds::class)->deadline($purchase)->utc()->toIso8601String())->toBe('2026-11-04T15:00:00+00:00');
});

test('a definitively failed refund needs an explicit verified restoration decision', function (): void {
    $refund = app(BadgeTemplateRefunds::class)->request($this->purchase, $this->actor, (string) Str::uuid(), 'Failed refund test');
    expect(fn () => app(BadgeTemplateRefunds::class)->restore($refund, $this->actor, (string) Str::uuid(), 'Reopen access'))->toThrow(ValidationException::class);
});

test('access can be restored only after the original provider confirms refund failure', function (): void {
    app(LedgerService::class)->postTransaction($this->purchase->order->tenant, null, 'badge_template_reserve_funding', 'Test funding', 'failed-funding', [
        ['code' => '1020', 'direction' => 'debit', 'amount' => 10000], ['code' => '1010', 'direction' => 'credit', 'amount' => 10000],
    ], null, 'GHS', App\Enum\LedgerCategoryEnum::Commerce);
    Http::fake(['*api.paystack.co/refund' => Http::response(['data' => ['id' => 124, 'status' => 'pending']])]);
    $refund = app(BadgeTemplateRefunds::class)->request($this->purchase, $this->actor, (string) Str::uuid(), 'Failure test');
    Http::fake([
        '*api.paystack.co/refund/124' => Http::response(['data' => ['id' => 124, 'status' => 'failed', 'amount' => 5000, 'currency' => 'GHS', 'merchant_note' => 'store:'.$refund->operation_key, 'transaction' => ['id' => 988]]]),
        '*api.paystack.co/transaction/verify/*' => Http::response(['data' => ['id' => 988, 'reference' => $this->attempt->reference, 'status' => 'success', 'amount' => 5000, 'fees' => 100, 'currency' => 'GHS']]),
    ]);
    $restoreKey = (string) Str::uuid();
    app(BadgeTemplateRefunds::class)->restore($refund, $this->actor, $restoreKey, 'Verified failure; reopen paid access');
    expect(fn () => app(BadgeTemplateRefunds::class)->restore($refund, $this->actor, $restoreKey, 'A changed restoration reason'))->toThrow(ValidationException::class);
    expect($this->purchase->licence->fresh()->state)->toBe('active')->and($this->purchase->order->fresh()->payment_state)->toBe('paid')
        ->and($refund->fresh()->status)->not->toBe('confirmed');
});

test('the platform digital sales desk requires platform finance and offers captured refunds', function (): void {
    $this->actingAs($this->actor)->get('/admin/badge-template-sales')->assertOk()->assertSee('Badge template sales')->assertSee($this->purchase->event->name)->assertSee('<span class="menu-title">Badge template sales</span>', false);
    $this->purchase->event->delete();
    $this->get('/admin/badge-template-sales')->assertOk()->assertSee('Badge template sales');
    $tenantActor = User::factory()->create(['tenant_id' => $this->purchase->buyer_tenant_id]);
    $this->actingAs($tenantActor)->get('/admin/badge-template-sales')->assertForbidden();
});

test('platform finance can approve a digital refund through the real desk while tenant staff cannot', function (): void {
    $path = '/admin/badge-template-sales/'.$this->purchase->id;
    $data = ['action' => 'refund', 'capture_id' => $this->capture->id, 'operation_key' => (string) Str::uuid(), 'reason' => 'Unused event licence refund requested'];
    $tenantActor = User::factory()->create(['tenant_id' => $this->purchase->buyer_tenant_id]);
    $this->actingAs($tenantActor)->post($path, $data)->assertForbidden();
    $this->actingAs($this->actor)->post($path, $data)->assertRedirect()->assertSessionHasNoErrors();
    expect($this->purchase->licence->fresh()->state)->toBe('suspended')
        ->and(App\Models\StoreRefund::query()->where('order_id', $this->purchase->order_id)->count())->toBe(1);
    $this->post($path, $data)->assertRedirect()->assertSessionHasNoErrors();
    expect(App\Models\StoreRefund::query()->where('order_id', $this->purchase->order_id)->count())->toBe(1);
    Http::assertNothingSent();
});

test('platform digital finance actions use the accessible high contrast button treatment', function (): void {
    $this->actingAs($this->actor)->get('/admin/badge-template-sales')->assertOk()
        ->assertSee('<button class="btn btn-sm btn-light-dark">Verify original payment</button>', false)
        ->assertSee('<button class="btn btn-sm btn-light-dark">Approve full refund</button>', false);
    expect(file_get_contents(resource_path('views/admin/badge-template-sales.blade.php')))->not->toContain('btn-light-warning');
});
