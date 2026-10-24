<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\StoreRefund;
use App\Services\Marketplace\StoreRefunds;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\StoreCheckoutScenario;

function grantStoreFinance(App\Models\User $actor, string $tenantId): void
{
    setPermissionsTeamId($tenantId);
    Permission::query()->firstOrCreate(['name' => 'manage store finance', 'guard_name' => 'web'], ['category' => 'store']);
    $actor->givePermissionTo('manage store finance');
}

it('records an unfunded full refund without calling the provider or inventing gateway credits', function (): void {
    [$tenant, $variant, $actor, , $order] = StoreCheckoutScenario::paid();
    grantStoreFinance($actor, $tenant->id);
    Http::fake();
    $refund = app(StoreRefunds::class)->request($order, $actor, (string) Str::uuid(), 'Not collected');
    expect($refund->status)->toBe('needs_funding')->and($refund->amount_pesewas)->toBe(10000)
        ->and($order->fresh()->payment_state)->toBe('refund_pending')->and($variant->fresh()->on_hand)->toBe(2);
    Http::assertNothingSent();
});

it('sends a funded full refund once and restocks only after verified completion', function (): void {
    [$tenant, $variant, $actor, $session, $order, $attempt] = StoreCheckoutScenario::paid();
    grantStoreFinance($actor, $tenant->id);
    $second = \Tests\Support\StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    $secondAttempt = app(App\Services\Marketplace\StorePayments::class)->start($second, $session, (string) Str::uuid());
    $capture = app(App\Services\Marketplace\StorePaymentEvidence::class)->record($secondAttempt, StoreCheckoutScenario::evidence($secondAttempt));
    app(App\Services\Marketplace\StorePaymentConfirmer::class)->confirm($capture);
    $key = (string) Str::uuid();
    Http::fake(['*api.paystack.co/refund' => Http::response(['data' => ['id' => 123, 'status' => 'pending']])]);
    $refund = app(StoreRefunds::class)->request($order, $actor, $key, 'Not collected');
    $retry = app(StoreRefunds::class)->request($order, $actor, $key, 'Not collected');
    expect($refund->id)->toBe($retry->id)->and($variant->fresh()->on_hand)->toBe(1)->and(StoreRefund::query()->count())->toBe(1);
    Http::fake([
        '*api.paystack.co/refund/123' => Http::response(['data' => ['id' => 123, 'status' => 'processed', 'amount' => 10000, 'currency' => 'GHS', 'merchant_note' => 'store:'.$key, 'transaction' => ['id' => 987]]]),
        '*api.paystack.co/transaction/verify/*' => Http::response(['data' => ['id' => 987, 'reference' => $attempt->reference, 'status' => 'success', 'amount' => 10000, 'fees' => 195, 'currency' => 'GHS']]),
    ]);
    app(StoreRefunds::class)->reconcile($refund);
    app(StoreRefunds::class)->reconcile($refund);
    expect($refund->fresh()->status)->toBe('confirmed')->and($variant->fresh()->on_hand)->toBe(2)
        ->and($order->fresh()->payment_state)->toBe('refunded')
        ->and(App\Models\StoreStockMovement::query()->where('source_kind', 'refund')->where('source_id', $refund->id)->count())->toBe(1);
});

it('refuses another organisation or an already collected order', function (bool $foreign): void {
    [$tenant, , $actor, , $order] = StoreCheckoutScenario::paid();
    grantStoreFinance($actor, $tenant->id);
    if ($foreign) {
        $actor = App\Models\User::factory()->create();
    } else {
        $order->update(['fulfilment_state' => 'collected']);
    }
    expect(fn () => app(StoreRefunds::class)->request($order, $actor, (string) Str::uuid(), 'Return'))->toThrow($foreign ? Illuminate\Auth\Access\AuthorizationException::class : Illuminate\Validation\ValidationException::class);
    expect(StoreRefund::query()->count())->toBe(0);
})->with([true, false]);

it('cancels an uncollected free order with one stock reversal and no gateway or money ledger', function (): void {
    [$tenant, $variant, $actor, $session] = StoreCheckoutScenario::make(1);
    grantStoreFinance($actor, $tenant->id);
    $variant->update(['price_pesewas' => 0]);
    $order = \Tests\Support\StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    app(App\Services\Marketplace\StorePayments::class)->start($order, $session, (string) Str::uuid());
    $key = (string) Str::uuid();
    Http::fake();
    $refund = app(StoreRefunds::class)->cancelFree($order->fresh(), $actor, $key, 'Not collected');
    $same = app(StoreRefunds::class)->cancelFree($order->fresh(), $actor, $key, 'Not collected');
    expect($same->id)->toBe($refund->id)->and($variant->fresh()->on_hand)->toBe(1)
        ->and($order->fresh()->payment_state)->toBe('refunded')
        ->and(App\Models\LedgerTransaction::withoutGlobalScopes()->count())->toBe(0);
    Http::assertNothingSent();
});

it('refuses partial amounts at the tenant refund boundary and denies foreign orders', function (): void {
    [$tenant, , $actor, , $order] = StoreCheckoutScenario::paid();
    grantStoreFinance($actor, $tenant->id);
    $actor->tenants()->attach($tenant->id);
    $host = $tenant->slug.'.'.mb_ltrim((string) config('session.domain'), '.');
    $this->actingAs($actor)->postJson('http://'.$host.'/store/orders/'.$order->id.'/refunds', ['operation_key' => (string) Str::uuid(), 'reason' => 'Return', 'amount' => 1], ['HTTP_HOST' => $host])
        ->assertUnprocessable()->assertJsonValidationErrors('amount');
    $foreign = App\Models\StoreOrder::factory()->create();
    $this->actingAs($actor)->postJson('http://'.$host.'/store/orders/'.$foreign->id.'/refunds', ['operation_key' => (string) Str::uuid(), 'reason' => 'Return'], ['HTTP_HOST' => $host])->assertNotFound();
});

it('does not resend a refund after a lost submission response or an empty paginated lookup', function (): void {
    [$tenant, $variant, $actor, $session, $order, $attempt] = StoreCheckoutScenario::paid();
    grantStoreFinance($actor, $tenant->id);
    StoreCheckoutScenario::anotherPaid($tenant, $variant, $session);
    $key = (string) Str::uuid();
    Http::fake(['*api.paystack.co/refund' => fn () => throw new Illuminate\Http\Client\ConnectionException('Unknown response')]);
    $refund = app(StoreRefunds::class)->request($order, $actor, $key, 'Not collected');
    expect($refund->status)->toBe('unknown');
    Http::fake([
        '*api.paystack.co/transaction/verify/*' => Http::response(['data' => ['id' => 987, 'reference' => $attempt->reference, 'status' => 'success', 'amount' => 10000, 'fees' => 195, 'currency' => 'GHS']]),
        '*api.paystack.co/refund*' => Http::response(['data' => [], 'meta' => ['pageCount' => 1]]),
    ]);
    app(StoreRefunds::class)->request($order, $actor, $key, 'Not collected');
    app(StoreRefunds::class)->reconcile($refund);
    expect($refund->fresh()->status)->toBe('unknown')->and($variant->fresh()->on_hand)->toBe(1);
    Http::assertNotSent(fn ($r) => $r->method() === 'POST');
});

it('repairs a verified refund after ledger failure without another provider call or stock reversal', function (): void {
    [$tenant, $variant, $actor, $session, $order, $attempt] = StoreCheckoutScenario::paid();
    grantStoreFinance($actor, $tenant->id);
    StoreCheckoutScenario::anotherPaid($tenant, $variant, $session);
    $key = (string) Str::uuid();
    Http::fake(['*api.paystack.co/refund' => Http::response(['data' => ['id' => 124, 'status' => 'pending']])]);
    $refund = app(StoreRefunds::class)->request($order, $actor, $key, 'Not collected');
    Http::fake([
        '*api.paystack.co/refund/124' => Http::response(['data' => ['id' => 124, 'status' => 'processed', 'amount' => 10000, 'currency' => 'GHS', 'merchant_note' => 'store:'.$key, 'transaction' => ['id' => 987]]]),
        '*api.paystack.co/transaction/verify/*' => Http::response(['data' => ['id' => 987, 'reference' => $attempt->reference, 'status' => 'success', 'amount' => 10000, 'fees' => 195, 'currency' => 'GHS']]),
    ]);
    $db = $order->getConnection();
    $db->statement("ALTER TABLE ledger_transactions ADD CONSTRAINT test_store_refund_failure CHECK (transaction_type <> 'commerce_refund')");
    expect(fn () => app(StoreRefunds::class)->reconcile($refund))->toThrow(Illuminate\Database\QueryException::class);
    expect($refund->fresh()->provider_processed_at)->not->toBeNull()->and($variant->fresh()->on_hand)->toBe(1);
    $db->statement('ALTER TABLE ledger_transactions DROP CONSTRAINT test_store_refund_failure');
    Http::fake();
    app(StoreRefunds::class)->reconcile($refund);
    app(StoreRefunds::class)->reconcile($refund);
    expect($refund->fresh()->status)->toBe('confirmed')->and($variant->fresh()->on_hand)->toBe(2);
    Http::assertNothingSent();
});

it('refunds an extra capture without returning already consumed merchandise to stock', function (): void {
    [$tenant, $variant, $actor, , $order] = StoreCheckoutScenario::paid();
    grantStoreFinance($actor, $tenant->id);
    $attempt = App\Models\StorePaymentAttempt::factory()->create(['order_id' => $order->id]);
    $extra = app(App\Services\Marketplace\StorePaymentEvidence::class)->record($attempt, StoreCheckoutScenario::evidence($attempt));
    app(App\Services\Marketplace\StorePaymentConfirmer::class)->confirm($extra);
    $key = (string) Str::uuid();
    Http::fake(['*api.paystack.co/refund' => Http::response(['data' => ['id' => 125, 'status' => 'pending']])]);
    $refund = app(StoreRefunds::class)->request($order->fresh(), $actor, $key, 'Duplicate capture', $extra->id);
    Http::fake([
        '*api.paystack.co/refund/125' => Http::response(['data' => ['id' => 125, 'status' => 'processed', 'amount' => 10000, 'currency' => 'GHS', 'merchant_note' => 'store:'.$key, 'transaction' => ['id' => 987]]]),
        '*api.paystack.co/transaction/verify/*' => Http::response(['data' => ['id' => 987, 'reference' => $attempt->reference, 'status' => 'success', 'amount' => 10000, 'fees' => 195, 'currency' => 'GHS']]),
    ]);
    app(StoreRefunds::class)->reconcile($refund);
    expect($refund->fresh()->status)->toBe('confirmed')->and($refund->restock)->toBeFalse()
        ->and($variant->fresh()->on_hand)->toBe(2)->and($order->fresh()->payment_state)->toBe('paid');
});

it('exposes full refund recovery and free cancellation through protected tenant endpoints', function (): void {
    [$tenant, $variant, $actor, $session, $order] = StoreCheckoutScenario::paid();
    grantStoreFinance($actor, $tenant->id);
    $actor->tenants()->attach($tenant->id);
    $host = $tenant->slug.'.'.mb_ltrim((string) config('session.domain'), '.');
    $base = 'http://'.$host.'/store';
    Http::fake();
    $result = $this->actingAs($actor)->postJson($base.'/orders/'.$order->id.'/refunds', ['operation_key' => (string) Str::uuid(), 'reason' => 'Not collected'], ['HTTP_HOST' => $host])
        ->assertOk()->assertJsonPath('status', 'needs_funding');
    $this->postJson($base.'/refunds/'.$result->json('refund_id').'/reconcile', [], ['HTTP_HOST' => $host])->assertOk()->assertJsonPath('status', 'needs_funding');
    $variant->update(['price_pesewas' => 0]);
    $free = \Tests\Support\StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    app(App\Services\Marketplace\StorePayments::class)->start($free, $session, (string) Str::uuid());
    $this->postJson($base.'/orders/'.$free->id.'/cancel-free', ['operation_key' => (string) Str::uuid(), 'reason' => 'Not collected'], ['HTTP_HOST' => $host])->assertOk()->assertJsonPath('status', 'confirmed');
    Http::assertNothingSent();
});
