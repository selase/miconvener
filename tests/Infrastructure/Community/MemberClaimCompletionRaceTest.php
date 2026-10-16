<?php

declare(strict_types=1);

use App\Models\CommunityAccessClaim;
use App\Models\CommunityAccessCredential;
use App\Models\Member;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

uses(DatabaseTruncation::class);

afterEach(function (): void {
    $this->truncateDatabaseTables();
});

it('completes a reviewed claim only once across two workers', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->features()->create(['feature_key' => 'community', 'enabled' => true]);
    $member = Member::factory()->create(['tenant_id' => $tenant->id, 'phone' => '+233241234567']);
    $claim = CommunityAccessClaim::factory()->create(['tenant_id' => $tenant->id, 'member_id' => $member->id, 'member_access_version' => 1,
        'status' => 'approved', 'method' => 'in_person', 'capability_hash' => hash('sha256', str_repeat('a', 64))]);
    $environment = ['DATABASE_URL' => '', 'DB_DATABASE' => (string) config('database.connections.landlord.database'), 'DB_HOST' => (string) config('database.connections.landlord.host'), 'APP_ENV' => 'testing', 'TELESCOPE_ENABLED' => 'false', 'BCRYPT_ROUNDS' => '4'];
    $workers = array_map(fn () => new Process([PHP_BINARY, base_path('tests/Fixtures/community/member-claim-worker.php'), $claim->id], base_path(), $environment), [1, 2]);
    $connection = DB::connection('landlord');
    $connection->beginTransaction();
    Member::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
    try {
        foreach ($workers as $worker) {
            $worker->start();
        }
        $deadline = microtime(true) + 20;
        do {
            $ready = count(array_filter($workers, fn ($worker) => str_contains($worker->getOutput(), 'READY')));
            usleep(20000);
        } while ($ready < 2 && microtime(true) < $deadline);
        expect($ready)->toBe(2);
        $deadline = microtime(true) + 10;
        do {
            $connection->select('select pg_stat_clear_snapshot()');
            $waiting = (int) $connection->selectOne("select count(*) as waiting from pg_stat_activity where datname=current_database() and wait_event_type='Lock'")->waiting;
            usleep(20000);
        } while ($waiting < 2 && microtime(true) < $deadline);
        expect($waiting)->toBeGreaterThanOrEqual(2);
        $connection->commit();
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput().$worker->getOutput());
        }
        expect(count(array_filter($workers, fn ($worker) => str_contains($worker->getOutput(), 'SUCCESS'))))->toBe(1)
            ->and(CommunityAccessCredential::query()->where('member_id', $member->id)->count())->toBe(1)
            ->and($claim->fresh()->status)->toBe('completed');
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
