<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\StoreCollection;
use App\Models\Tenant;
use App\Services\Marketplace\StoreCollectionPasses;
use App\Services\Marketplace\StoreFulfilment;
use App\Services\Marketplace\StorePayments;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\StoreCheckoutScenario;

uses(DatabaseTruncation::class);
afterEach(function (): void {
    $this->truncateDatabaseTables();
});

it('serialises real workers at the tenant/order boundary for full handover and cancellation', function (array $actions, bool $free, array $expected) {
    Queue::fake();
    if ($free) {
        [$tenant,$variant,$actor,$session] = StoreCheckoutScenario::make(1);
        $variant->update(['price_pesewas' => 0]);
        $order = StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
        app(StorePayments::class)->start($order, $session, (string) Str::uuid());
        $order = $order->fresh();
        $tenant->features()->create(['feature_key' => 'commerce_collection', 'enabled' => true]);
        Permission::query()->firstOrCreate(['name' => 'manage store collection', 'guard_name' => 'web'], ['category' => 'store']);
        $actor->givePermissionTo('manage store collection');
        app(StoreFulfilment::class)->ready($order, $actor, (string) Str::uuid());
    } else {
        [$tenant,$variant,$actor,$session,$order] = StoreCheckoutScenario::collectionReady();
    }
    Permission::query()->firstOrCreate(['name' => 'manage store finance', 'guard_name' => 'web'], ['category' => 'store']);
    $actor->givePermissionTo('manage store finance');
    $proof = app(StoreCollectionPasses::class)->issue($order, $session, (string) Str::uuid());
    $key = (string) Str::uuid();
    $before = $variant->fresh()->on_hand;
    $environment = ['DATABASE_URL' => '', 'DB_DATABASE' => (string) config('database.connections.landlord.database'), 'DB_HOST' => (string) config('database.connections.landlord.host'), 'APP_ENV' => 'testing', 'TELESCOPE_ENABLED' => 'false', 'PAO_DISABLE' => '1'];
    $workers = [];
    foreach ($actions as $action) {
        $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/marketplace/collection-worker.php')], base_path(), $environment);
        $worker->setTimeout(90)->setInput(json_encode(['order' => $order->id, 'actor' => (string) $actor->id, 'action' => $action, 'credential' => ['manual_code' => $proof['manual_code']], 'key' => $key], JSON_THROW_ON_ERROR));
        $workers[] = $worker;
    }
    $db = $order->getConnection();
    $db->beginTransaction();
    Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
    try {
        foreach ($workers as $index => $worker) {
            $worker->start();
            $deadline = microtime(true) + 30;
            do {
                $db->select('select pg_stat_clear_snapshot()');
                $waiting = (int) $db->selectOne("select count(*) as waiting from pg_stat_activity where datname=current_database() and application_name='store-collection-worker' and wait_event_type='Lock'")->waiting;
                usleep(20000);
            } while ($waiting < $index + 1 && microtime(true) < $deadline);
            expect($waiting)->toBeGreaterThanOrEqual($index + 1);
        }
        $db->commit();
        foreach ($workers as $index => $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
            expect(mb_trim(str_replace('READY', '', $worker->getOutput())))->toBe($expected[$index]);
        }
        $collected = in_array('COLLECTED', $expected, true);
        expect(StoreCollection::query()->where('order_id', $order->id)->count())->toBe($collected ? 1 : 0)
            ->and($variant->fresh()->on_hand)->toBe($before + ($free && ! $collected ? 1 : 0));
    } finally {
        if ($db->transactionLevel() > 0) {
            $db->rollBack();
        }
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }
})->with([
    'collection wins' => [['collect', 'refund'], false, ['COLLECTED', 'BLOCKED']],
    'refund wins' => [['refund', 'collect'], false, ['REFUND', 'BLOCKED']],
    'same confirmation retry' => [['collect', 'collect'], false, ['COLLECTED', 'COLLECTED']],
    'free cancel wins' => [['cancel', 'collect'], true, ['CANCELLED', 'BLOCKED']],
    'free collection wins' => [['collect', 'cancel'], true, ['COLLECTED', 'BLOCKED']],
]);
