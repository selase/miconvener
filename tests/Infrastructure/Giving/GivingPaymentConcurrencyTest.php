<?php

declare(strict_types=1);

use App\Models\GivingFund;
use App\Models\GivingGift;
use App\Models\GivingPaymentAttempt;
use App\Models\LedgerTransaction;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\GivingScenario;

uses(DatabaseTruncation::class);

afterEach(function (): void {
    $this->truncateDatabaseTables();
});

it('serialises duplicate checkout and provider confirmations across actual workers', function (string $mode): void {
    [, , $fund, $attempt] = GivingScenario::online();
    $key = $mode === 'confirm' ? $attempt->id : (string) Str::uuid();
    $environment = ['DATABASE_URL' => '', 'DB_DATABASE' => (string) config('database.connections.landlord.database'), 'DB_HOST' => (string) config('database.connections.landlord.host'), 'APP_ENV' => 'testing', 'TELESCOPE_ENABLED' => 'false', 'PAO_DISABLE' => '1'];
    $workers = array_map(fn () => new Process([PHP_BINARY, base_path('tests/Fixtures/giving/payment-worker.php'), $fund->id, $mode, $key], base_path(), $environment), [0, 1]);
    $connection = DB::connection('landlord');
    $connection->beginTransaction();
    GivingFund::withoutGlobalScopes()->lockForUpdate()->findOrFail($fund->id);
    try {
        foreach ($workers as $index => $worker) {
            $worker->start();
            $deadline = microtime(true) + 20;
            do {
                $connection->select('select pg_stat_clear_snapshot()');
                $waiting = (int) $connection->selectOne("select count(*) as waiting from pg_stat_activity where datname=current_database() and application_name='giving-payment-worker' and wait_event_type='Lock'")->waiting;
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
        if ($mode === 'confirm') {
            expect($attempt->fresh()->state)->toBe('confirmed')->and(LedgerTransaction::query()->count())->toBe(1)
                ->and(mb_substr_count($output, 'CONFIRMED'))->toBe(2);
        } else {
            expect(GivingPaymentAttempt::withoutGlobalScopes()->where('submission_key', $key)->count())->toBe(1)
                ->and(GivingGift::withoutGlobalScopes()->where('submission_key', $key)->count())->toBe(1)
                ->and(mb_substr_count($output, 'INITIALIZED'))->toBe(1);
        }
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
})->with(['checkout', 'confirm']);
