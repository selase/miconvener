<?php

declare(strict_types=1);

use App\Models\BadgeTemplatePurchase;
use App\Models\StorePaymentAttempt;
use App\Models\StorePaymentObservation;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

uses(DatabaseTruncation::class);

beforeEach(function (): void {
    expect((string) config('database.connections.landlord.database'))->toContain('testing');
    config(['services.settlement.paystack.secret_key' => 'sk_test_badge_races']);
    Queue::fake();
});

afterEach(function (): void {
    $this->truncateDatabaseTables();
});

/** @return array{purchase: BadgeTemplatePurchase, capture: StorePaymentObservation} */
function paidBadgeRaceFixture(): array
{
    $fingerprint = hash('sha256', app(App\Services\Giving\GivingGateways::class)->platformIdentity());
    $purchase = BadgeTemplatePurchase::factory()->create(['order_id' => App\Models\StoreOrder::factory()->badgeTemplate()->state(['account_fingerprint' => $fingerprint])]);
    $purchase->order->update(['accepted_at' => now()]);
    $attempt = StorePaymentAttempt::factory()->create(['order_id' => $purchase->order_id]);
    $capture = StorePaymentObservation::factory()->create(['attempt_id' => $attempt->id, 'gateway_fee_pesewas' => 100]);

    return compact('purchase', 'capture');
}

/** @param array<string, mixed> $input */
function paidBadgeWorker(array $input): Process
{
    $environment = ['DATABASE_URL' => '', 'DB_DATABASE' => (string) config('database.connections.landlord.database'), 'DB_HOST' => (string) config('database.connections.landlord.host'), 'APP_ENV' => 'testing', 'TELESCOPE_ENABLED' => 'false', 'PAO_DISABLE' => '1'];

    return (new Process([PHP_BINARY, base_path('tests/Fixtures/badges/paid-template-worker.php')], base_path(), $environment))->setTimeout(90)->setInput(json_encode($input, JSON_THROW_ON_ERROR));
}

test('paid issuance waits for event deletion and keeps a deleted target capture in review', function (): void {
    ['purchase' => $purchase, 'capture' => $capture] = paidBadgeRaceFixture();
    $connection = DB::connection('landlord');
    $worker = paidBadgeWorker(['action' => 'confirm', 'capture' => $capture->id]);
    $connection->beginTransaction();
    App\Models\Event::query()->lockForUpdate()->findOrFail($purchase->event_id);
    try {
        $worker->start();
        $deadline = microtime(true) + 30;
        do {
            $connection->select('select pg_stat_clear_snapshot()');
            $waiting = (int) $connection->selectOne("select count(*) as n from pg_stat_activity where datname=current_database() and application_name='paid-badge-worker' and wait_event_type='Lock'")->n;
            usleep(20000);
        } while ($waiting === 0 && $worker->isRunning() && microtime(true) < $deadline);
        expect($waiting)->toBe(1, 'Payment issuance must lock its event before checking that it is still available.');
        $purchase->event->delete();
        $connection->commit();
        $worker->wait();
        expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
        expect($capture->fresh()->processing_state)->toBe('review')->and($purchase->licence->fresh()->state)->not->toBe('active');
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        if ($worker->isRunning()) {
            $worker->stop();
        }
    }
});

/** @param list<array<string, mixed>> $jobs
 * @return list<string>
 */
function paidBadgeRace(string $eventId, array $jobs): array
{
    $connection = DB::connection('landlord');
    $workers = array_map(fn (array $job): Process => paidBadgeWorker($job), $jobs);
    $connection->beginTransaction();
    App\Models\Event::query()->lockForUpdate()->findOrFail($eventId);
    try {
        foreach ($workers as $index => $worker) {
            $worker->start();
            $deadline = microtime(true) + 30;
            do {
                $connection->select('select pg_stat_clear_snapshot()');
                $waiting = (int) $connection->selectOne("select count(*) as n from pg_stat_activity where datname=current_database() and application_name='paid-badge-worker' and wait_event_type='Lock'")->n;
                usleep(20000);
            } while ($waiting < $index + 1 && microtime(true) < $deadline);
            expect($waiting)->toBeGreaterThanOrEqual($index + 1);
        }
        $connection->commit();
        $results = [];
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput().$worker->getOutput());
            $results[] = mb_trim(str_replace("READY\n", '', $worker->getOutput()));
        }

        return $results;
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }
}

/** @return array{buyer: App\Models\User, finance: App\Models\User} */
function paidBadgeRaceActors(BadgeTemplatePurchase $purchase): array
{
    $buyer = App\Models\User::factory()->create(['tenant_id' => $purchase->buyer_tenant_id]);
    $buyer->tenants()->attach($purchase->buyer_tenant_id);
    setPermissionsTeamId($purchase->buyer_tenant_id);
    foreach (['read badge-template', 'update badge-template', 'purchase badge-templates'] as $name) {
        App\Models\Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['category' => 'event']);
        $buyer->givePermissionTo($name);
    }
    $finance = App\Models\User::factory()->create();
    setPermissionsTeamId(null);
    $permission = App\Models\Permission::query()->firstOrCreate(['name' => 'manage template refunds', 'guard_name' => 'web'], ['category' => 'platform']);
    $role = App\Models\Role::query()->create(['name' => 'Paid badge race finance', 'guard_name' => 'web', 'tenant_id' => null]);
    $role->givePermissionTo($permission);
    $finance->assignRole($role);

    return compact('buyer', 'finance');
}

test('simultaneous callback and webhook confirmations issue one licence and balanced sale', function (): void {
    ['purchase' => $purchase, 'capture' => $capture] = paidBadgeRaceFixture();
    paidBadgeRace($purchase->event_id, [['action' => 'confirm', 'capture' => $capture->id], ['action' => 'confirm', 'capture' => $capture->id]]);
    expect($purchase->fresh()->state)->toBe('paid')->and($purchase->licence->fresh()->state)->toBe('active');
    expect(App\Models\LedgerTransaction::withoutGlobalScopes()->where('transaction_type', 'badge_template_sale')->count())->toBe(1);
    expect(App\Models\LedgerTransaction::withoutGlobalScopes()->where('transaction_type', 'badge_template_sale')->firstOrFail()->isBalanced())->toBeTrue();
    expect(App\Models\BadgeTemplateLicenceEvent::query()->where('action', 'issued')->count())->toBe(1);
});

test('a refund racing first use has one winner and cannot emit suspended artwork', function (): void {
    ['purchase' => $purchase, 'capture' => $capture] = paidBadgeRaceFixture();
    app(App\Services\Marketplace\StorePaymentConfirmer::class)->confirm($capture);
    ['buyer' => $buyer, 'finance' => $finance] = paidBadgeRaceActors($purchase);
    app(App\Services\Badges\BadgeTemplateService::class)->forEvent($purchase->event);
    App\Models\BadgeDesignProvenance::query()->create(['source_type' => 'event', 'source_id' => $purchase->event_id, 'family_id' => $purchase->family_id]);
    $results = paidBadgeRace($purchase->event_id, [
        ['action' => 'refund', 'purchase' => $purchase->id, 'actor' => $finance->id, 'key' => (string) Illuminate\Support\Str::uuid()],
        ['action' => 'use', 'event' => $purchase->event_id, 'actor' => $buyer->id, 'key' => (string) Illuminate\Support\Str::uuid()],
    ]);
    $this->assertCount(1, array_filter($results, fn (string $result): bool => $result === 'REFUSED'), json_encode($results, JSON_THROW_ON_ERROR));
    if ($purchase->licence->fresh()->state === 'suspended') {
        expect(App\Models\BadgeTemplateUse::query()->where('purchase_id', $purchase->id)->count())->toBe(0);
    } else {
        expect($purchase->licence->fresh()->state)->toBe('active')->and(App\Models\BadgeTemplateUse::query()->where('purchase_id', $purchase->id)->count())->toBe(1);
    }
});

test('two finance workers requesting a refund retain one refund and one suspension decision', function (): void {
    ['purchase' => $purchase, 'capture' => $capture] = paidBadgeRaceFixture();
    app(App\Services\Marketplace\StorePaymentConfirmer::class)->confirm($capture);
    ['finance' => $finance] = paidBadgeRaceActors($purchase);
    paidBadgeRace($purchase->event_id, [
        ['action' => 'refund', 'purchase' => $purchase->id, 'actor' => $finance->id, 'key' => (string) Illuminate\Support\Str::uuid()],
        ['action' => 'refund', 'purchase' => $purchase->id, 'actor' => $finance->id, 'key' => (string) Illuminate\Support\Str::uuid()],
    ]);
    expect(App\Models\StoreRefund::query()->where('order_id', $purchase->order_id)->count())->toBe(1)
        ->and(App\Models\BadgeTemplateLicenceEvent::query()->where('action', 'refund_approved')->count())->toBe(1);
});

test('two purchasers of the same event design share one pending licence quote', function (): void {
    $purchase = BadgeTemplatePurchase::factory()->create(['state' => 'cancelled']);
    ['buyer' => $buyer] = paidBadgeRaceActors($purchase);
    $seller = $purchase->order->tenant;
    App\Models\StoreCommercialAgreement::factory()->create(['tenant_id' => $seller->id, 'shop_id' => $purchase->order->shop_id, 'version' => 2, 'offering_scope' => 'badge_template', 'percentage' => '0.00', 'refund_gateway_fee_bearer' => 'platform', 'account_fingerprint' => hash('sha256', app(App\Services\Giving\GivingGateways::class)->platformIdentity())]);
    $purchase->event->tenant->features()->create(['feature_key' => 'paid_badge_templates', 'enabled' => true]);
    app(App\Services\Finance\LedgerService::class)->postTransaction($seller, null, 'badge_template_reserve_funding', 'Synthetic race reserve', 'race-reserve', [['code' => '1020', 'direction' => 'debit', 'amount' => 10000], ['code' => '1010', 'direction' => 'credit', 'amount' => 10000]], null, 'GHS', App\Enum\LedgerCategoryEnum::Commerce);
    $otherBuyer = App\Models\User::factory()->create(['tenant_id' => $purchase->buyer_tenant_id]);
    $otherBuyer->tenants()->attach($purchase->buyer_tenant_id);
    setPermissionsTeamId($purchase->buyer_tenant_id);
    $otherBuyer->givePermissionTo('purchase badge-templates');
    $job = ['action' => 'quote', 'event' => $purchase->event_id, 'version' => $purchase->version_id, 'seller' => $seller->id, 'actor' => $buyer->id];
    $results = paidBadgeRace($purchase->event_id, [[...$job, 'key' => (string) Illuminate\Support\Str::uuid()], [...$job, 'actor' => $otherBuyer->id, 'key' => (string) Illuminate\Support\Str::uuid()]]);
    expect($results[0])->toBe($results[1])->and(BadgeTemplatePurchase::query()->where('state', 'pending')->count())->toBe(1);
});

test('an extra capture for a refunded purchase cannot claim its replacement licence', function (): void {
    ['purchase' => $purchase, 'capture' => $capture] = paidBadgeRaceFixture();
    app(App\Services\Marketplace\StorePaymentConfirmer::class)->confirm($capture);
    ['buyer' => $buyer, 'finance' => $finance] = paidBadgeRaceActors($purchase);
    $seller = $purchase->order->tenant;
    app(App\Services\Finance\LedgerService::class)->postTransaction($seller, null, 'badge_template_reserve_funding', 'Synthetic replacement reserve', 'replacement-reserve', [['code' => '1020', 'direction' => 'debit', 'amount' => 30000], ['code' => '1010', 'direction' => 'credit', 'amount' => 30000]], null, 'GHS', App\Enum\LedgerCategoryEnum::Commerce);
    Illuminate\Support\Facades\Http::fake(['*api.paystack.co/refund' => Illuminate\Support\Facades\Http::response(['data' => ['id' => 98765, 'status' => 'pending']])]);
    $refund = app(App\Services\Badges\BadgeTemplateRefunds::class)->request($purchase, $finance, (string) Illuminate\Support\Str::uuid(), 'Replace refunded licence');
    Illuminate\Support\Facades\Http::fake([
        '*api.paystack.co/refund/98765' => Illuminate\Support\Facades\Http::response(['data' => ['id' => 98765, 'status' => 'processed', 'amount' => 5000, 'currency' => 'GHS', 'merchant_note' => 'store:'.$refund->operation_key, 'transaction' => ['id' => 43210]]]),
        '*api.paystack.co/transaction/verify/*' => Illuminate\Support\Facades\Http::response(['data' => ['id' => 43210, 'reference' => $capture->reference, 'status' => 'success', 'amount' => 5000, 'fees' => 100, 'currency' => 'GHS']]),
    ]);
    app(App\Services\Marketplace\StoreRefunds::class)->reconcile($refund);
    expect($purchase->fresh()->state)->toBe('refunded');
    App\Models\StoreCommercialAgreement::factory()->create(['tenant_id' => $seller->id, 'shop_id' => $purchase->order->shop_id, 'version' => 2, 'offering_scope' => 'badge_template', 'percentage' => '0.00', 'refund_gateway_fee_bearer' => 'platform', 'account_fingerprint' => hash('sha256', app(App\Services\Giving\GivingGateways::class)->platformIdentity())]);
    $purchase->event->tenant->features()->create(['feature_key' => 'paid_badge_templates', 'enabled' => true]);
    $extraAttempt = StorePaymentAttempt::factory()->create(['order_id' => $purchase->order_id]);
    $extra = StorePaymentObservation::factory()->create(['attempt_id' => $extraAttempt->id, 'gateway_fee_pesewas' => 100]);
    paidBadgeRace($purchase->event_id, [
        ['action' => 'confirm', 'capture' => $extra->id],
        ['action' => 'quote', 'event' => $purchase->event_id, 'version' => $purchase->version_id, 'seller' => $seller->id, 'actor' => $buyer->id, 'key' => (string) Illuminate\Support\Str::uuid()],
    ]);
    expect($extra->fresh()->processing_state)->toBe('review')->and($purchase->fresh()->state)->toBe('refunded')
        ->and(BadgeTemplatePurchase::query()->where('state', 'pending')->count())->toBe(1)
        ->and($purchase->licence->fresh()->state)->not->toBe('active');
    foreach (App\Models\LedgerTransaction::withoutGlobalScopes()->get() as $transaction) {
        expect($transaction->isBalanced())->toBeTrue();
    }
});

test('paid money locks allow the event foreign key check of a design revision without deadlock', function (): void {
    ['purchase' => $purchase, 'capture' => $capture] = paidBadgeRaceFixture();
    app(App\Services\Marketplace\StorePaymentConfirmer::class)->confirm($capture);
    ['finance' => $finance] = paidBadgeRaceActors($purchase);
    $template = app(App\Services\Badges\BadgeTemplateService::class)->forEvent($purchase->event);
    $connection = DB::connection('landlord');
    $worker = paidBadgeWorker(['action' => 'refund', 'purchase' => $purchase->id, 'actor' => $finance->id, 'key' => (string) Illuminate\Support\Str::uuid()]);
    $connection->beginTransaction();
    try {
        App\Models\EventBadgeTemplate::withoutGlobalScopes()->whereKey($template->id)->lockForUpdate()->firstOrFail();
        App\Models\BadgeTemplateLicence::query()->whereKey($purchase->licence_id)->lockForUpdate()->firstOrFail();
        $worker->start();
        $deadline = microtime(true) + 30;
        do {
            $connection->select('select pg_stat_clear_snapshot()');
            $waiting = (int) $connection->selectOne("select count(*) as n from pg_stat_activity where datname=current_database() and application_name='paid-badge-worker' and wait_event_type='Lock'")->n;
            usleep(20000);
        } while ($waiting === 0 && microtime(true) < $deadline);
        expect($waiting)->toBe(1);
        App\Models\BadgeDesignRevision::query()->create(['tenant_id' => $purchase->buyer_tenant_id, 'event_id' => $purchase->event_id, 'design_version' => 1, 'snapshot' => []]);
        $connection->commit();
        $worker->wait();
        expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
        expect($purchase->licence->fresh()->state)->toBe('suspended');
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        if ($worker->isRunning()) {
            $worker->stop();
        }
    }
});
