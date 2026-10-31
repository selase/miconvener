<?php

declare(strict_types=1);

use App\Models\EventPayout;
use App\Models\GivingAdjustment;
use App\Models\TenantPayoutAccount;
use App\Services\Giving\GivingBalance;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\GivingScenario;

uses(DatabaseTruncation::class);

afterEach(function (): void {
    $this->truncateDatabaseTables();
});

it('serialises payout refund and mixed reservations on the same payable account', function (string $mode): void {
    [$tenant, $user, , $attempt] = GivingScenario::paid();
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'is_verified' => true, 'type' => TenantPayoutAccount::TYPE_MOBILE_MONEY]);
    $environment = ['DATABASE_URL' => '', 'DB_DATABASE' => (string) config('database.connections.landlord.database'), 'DB_HOST' => (string) config('database.connections.landlord.host'), 'APP_ENV' => 'testing', 'TELESCOPE_ENABLED' => 'false', 'PAO_DISABLE' => '1'];
    $kinds = match ($mode) {
        'payout' => ['payout', 'payout'], 'refund' => ['refund', 'refund'], default => ['payout', 'refund']
    };
    $workers = array_map(fn ($kind) => new Process([PHP_BINARY, base_path('tests/Fixtures/giving/reservation-worker.php'), $tenant->id, (string) $user->id, $kind, $kind === 'payout' ? $account->id : $attempt->gift_id, (string) Str::uuid()], base_path(), $environment), $kinds);
    $connection = DB::connection('landlord');
    $connection->beginTransaction();
    app(GivingBalance::class)->lockPayableAccount($tenant, 'GHS');
    try {
        foreach ($workers as $index => $worker) {
            $worker->start();
            $deadline = microtime(true) + 20;
            do {
                $connection->select('select pg_stat_clear_snapshot()');
                $waiting = (int) $connection->selectOne("select count(*) as waiting from pg_stat_activity where datname=current_database() and application_name='giving-reservation-worker' and wait_event_type='Lock'")->waiting;
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
        expect(mb_substr_count($output, 'RESERVED'))->toBe(1)->and(mb_substr_count($output, 'BLOCKED'))->toBe(1)
            ->and(EventPayout::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count() + GivingAdjustment::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1)
            ->and(app(GivingBalance::class)->available($tenant, 'GHS'))->toBe($mode === 'refund' ? 2770 : 2700);
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
})->with(['payout', 'refund', 'payout_then_refund']);
