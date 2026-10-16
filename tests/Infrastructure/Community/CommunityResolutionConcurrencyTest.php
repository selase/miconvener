<?php

declare(strict_types=1);

use App\Models\MessageOutcomeResolution;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Community\CommunitySmsBudget;
use App\Services\Sms\SmsAllowance;
use App\Services\Sms\SmsResult;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(DatabaseTruncation::class);

afterEach(function (): void {
    $this->truncateDatabaseTables();
});

test('concurrent operator resolutions refund a held reservation exactly once', function () {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    $tenant = Tenant::factory()->create();
    $tenant->features()->create(['feature_key' => 'sms_credits', 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => 2]]);
    $operator = User::factory()->create(['tenant_id' => null]);
    setPermissionsTeamId(null);
    $operator->assignRole(Role::query()->where('name', 'Superadmin')->whereNull('tenant_id')->firstOrFail());
    $reservation = app(CommunitySmsBudget::class)->reserve($tenant, 'community_message', (string) Str::uuid(), Carbon\CarbonImmutable::now());
    app(CommunitySmsBudget::class)->finish($tenant, $reservation->id, SmsResult::unknown('Interrupted'));
    $environment = ['DATABASE_URL' => '', 'DB_DATABASE' => (string) config('database.connections.landlord.database'), 'DB_HOST' => (string) config('database.connections.landlord.host'), 'APP_ENV' => 'testing', 'TELESCOPE_ENABLED' => 'false'];
    $workers = array_map(fn () => new Process([PHP_BINARY, base_path('tests/Fixtures/community/resolution-worker.php'), $tenant->id, (string) $operator->id, $reservation->id], base_path(), $environment), [1, 2]);
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
        expect(MessageOutcomeResolution::query()->where('tenant_id', $tenant->id)->count())->toBe(1)
            ->and(app(SmsAllowance::class)->remaining($tenant))->toBe(2);
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
});
