<?php

declare(strict_types=1);

use App\Models\CommunitySettings;
use App\Models\CommunitySmsReservation;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(DatabaseTruncation::class);

afterEach(function (): void {
    $this->truncateDatabaseTables();
});

test('a concurrent message and reminder cannot overspend the last credit or monthly slot', function (string $constraint) {
    $tenant = Tenant::factory()->create();
    $tenant->features()->create(['feature_key' => 'sms_credits', 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => $constraint === 'credit' ? 1 : 10]]);
    CommunitySettings::for($tenant)->fill(['sms_monthly_limit' => $constraint === 'monthly' ? 1 : null])->save();
    $environment = ['DATABASE_URL' => '', 'DB_DATABASE' => (string) config('database.connections.landlord.database'), 'DB_HOST' => (string) config('database.connections.landlord.host'), 'APP_ENV' => 'testing', 'TELESCOPE_ENABLED' => 'false'];
    $workers = array_map(fn ($source) => new Process([PHP_BINARY, base_path('tests/Fixtures/community/budget-worker.php'), $tenant->id, $source, (string) Str::uuid()], base_path(), $environment), ['dues_reminder', 'community_message']);
    $connection = DB::connection('landlord');
    $connection->beginTransaction();
    $connection->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['credits:sms_credits:'.$tenant->id]);
    try {
        foreach ($workers as $worker) {
            $worker->start();
        }
        $deadline = microtime(true) + 15;
        do {
            $ready = count(array_filter($workers, fn ($worker) => str_contains($worker->getOutput(), 'READY')));
            usleep(20000);
        } while ($ready < 2 && microtime(true) < $deadline);
        expect($ready)->toBe(2, implode(' | ', array_map(fn ($worker) => $worker->getOutput().$worker->getErrorOutput(), $workers)));
        $deadline = microtime(true) + 10;
        do {
            $connection->select('select pg_stat_clear_snapshot()');
            $waiting = (int) $connection->selectOne("select count(*) as waiting from pg_stat_activity where datname = current_database() and wait_event = 'advisory'")->waiting;
            usleep(20000);
        } while ($waiting < 2 && microtime(true) < $deadline);
        expect($waiting)->toBeGreaterThanOrEqual(2);
        $connection->commit();
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput().$worker->getOutput());
        }
        expect(CommunitySmsReservation::query()->where('tenant_id', $tenant->id)->count())->toBe(1);
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
})->with(['credit', 'monthly']);
