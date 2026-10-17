<?php

declare(strict_types=1);

use App\Models\GivingFund;
use App\Models\GivingGift;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\GivingScenario;

uses(DatabaseTruncation::class);

afterEach(function (): void {
    $this->truncateDatabaseTables();
});

it('serialises cash submissions across workers without duplicate gifts or missing auto-close', function (bool $duplicate): void {
    [, $user, $fund] = GivingScenario::make(['kind' => 'campaign', 'target' => 100000, 'close_on_target' => true]);
    $key = (string) Str::uuid();
    $environment = ['DATABASE_URL' => '', 'DB_DATABASE' => (string) config('database.connections.landlord.database'), 'DB_HOST' => (string) config('database.connections.landlord.host'), 'APP_ENV' => 'testing', 'TELESCOPE_ENABLED' => 'false', 'PAO_DISABLE' => '1'];
    $workers = [];
    foreach ([0, 1] as $index) {
        $workers[] = new Process([PHP_BINARY, base_path('tests/Fixtures/giving/cash-worker.php'), $fund->id, (string) $user->id, $duplicate || $index === 0 ? $key : (string) Str::uuid()], base_path(), $environment);
    }
    $connection = DB::connection('landlord');
    $connection->beginTransaction();
    GivingFund::withoutGlobalScopes()->lockForUpdate()->findOrFail($fund->id);
    try {
        foreach ($workers as $index => $worker) {
            $worker->start();
            $deadline = microtime(true) + 20;
            do {
                $connection->select('select pg_stat_clear_snapshot()');
                $waiting = (int) $connection->selectOne("select count(*) as waiting from pg_stat_activity where datname=current_database() and application_name='giving-cash-worker' and wait_event_type='Lock'")->waiting;
                usleep(20000);
            } while ($waiting < $index + 1 && microtime(true) < $deadline);
            expect($waiting)->toBeGreaterThanOrEqual($index + 1);
        }
        $connection->commit();
        $output = '';
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput().$worker->getOutput());
            $output .= $worker->getOutput();
        }
        expect(GivingGift::withoutGlobalScopes()->where('fund_id', $fund->id)->count())->toBe(1)
            ->and($fund->fresh()->state)->toBe('closed')
            ->and(mb_substr_count($output, 'DONE'))->toBe($duplicate ? 2 : 1)
            ->and(mb_substr_count($output, 'FUND_CLOSED'))->toBe($duplicate ? 0 : 1);
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
})->with([false, true]);
