<?php

declare(strict_types=1);

use App\Models\CommunityFollower;
use App\Models\Tenant;
use App\Services\Community\MessagingPreferences;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

uses(DatabaseTruncation::class);

afterEach(function (): void {
    $this->truncateDatabaseTables();
});

it('serialises duplicate follows and consent withdrawal on the same address', function (string $second): void {
    $tenant = Tenant::factory()->create();
    $tenant->features()->create(['feature_key' => 'community_following', 'enabled' => true]);
    $hash = app(MessagingPreferences::class)->hash('email', 'follow@example.test');
    $environment = ['DATABASE_URL' => '', 'DB_DATABASE' => (string) config('database.connections.landlord.database'), 'DB_HOST' => (string) config('database.connections.landlord.host'), 'APP_ENV' => 'testing', 'TELESCOPE_ENABLED' => 'false', 'PAO_DISABLE' => '1'];
    $workers = array_map(fn ($mode) => new Process([PHP_BINARY, base_path('tests/Fixtures/community/following-worker.php'), $tenant->id, $mode], base_path(), $environment), ['follow', $second]);
    $connection = DB::connection('landlord');
    $connection->beginTransaction();
    $connection->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['community-follow:'.$tenant->id.':email:'.$hash]);
    try {
        foreach ($workers as $index => $worker) {
            $worker->start();
            $deadline = microtime(true) + 20;
            do {
                $connection->select('select pg_stat_clear_snapshot()');
                $waiting = (int) $connection->selectOne("select count(*) as waiting from pg_stat_activity where datname=current_database() and wait_event='advisory'")->waiting;
                usleep(20000);
            } while ($waiting < $index + 1 && microtime(true) < $deadline);
            expect($waiting)->toBeGreaterThanOrEqual($index + 1);
        }
        $connection->commit();
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput().$worker->getOutput());
            expect($worker->getOutput())->toContain('DONE');
        }
        expect(CommunityFollower::query()->where('tenant_id', $tenant->id)->count())->toBe(1)
            ->and(app(MessagingPreferences::class)->allowsMarketing($tenant, 'email', 'follow@example.test'))->toBe($second !== 'unfollow');
        expect(CommunityFollower::query()->first()->unfollowed_at === null)->toBe($second !== 'unfollow');
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
})->with(['follow', 'unfollow']);
