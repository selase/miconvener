<?php

declare(strict_types=1);

use App\Models\Member;
use App\Models\MembershipType;
use App\Services\Community\MemberImport;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

uses(DatabaseTruncation::class);

test('concurrent confirmations and manual additions serialize identity and cap checks', function (string $race) {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    [$tenant, $user] = eventHost('race-'.bin2hex(random_bytes(5)));
    Artisan::call('community:enable', ['tenant' => $tenant->slug]);
    $tenant->features()->create(['feature_key' => 'community_members', 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => 1]]);
    $type = MembershipType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Regular']);
    $this->actingAs($user);
    config(['cache.default' => 'file', 'cache.stores.file.path' => sys_get_temp_dir().'/miconvener-import-concurrency-cache/'.$tenant->id]);
    $preview = app(MemberImport::class)->preview($tenant, UploadedFile::fake()->createWithContent('race.csv', "name,phone,email,membership_type,joined_on\nAma,0241234567,,Regular,\n"));
    $manual = ['name' => $race === 'cap' ? 'Kojo' : 'Ama', 'phone' => $race === 'cap' ? '0241234568' : '+233241234567', 'membership_type_id' => $type->id];
    $arguments = $race === 'same-preview' ? ['confirm', $preview->token] : ['manual', json_encode($manual, JSON_THROW_ON_ERROR)];
    $environment = ['DATABASE_URL' => '', 'DB_DATABASE' => (string) config('database.connections.landlord.database'), 'DB_HOST' => (string) config('database.connections.landlord.host'), 'APP_ENV' => 'testing'];
    $workers = [
        new Process([PHP_BINARY, base_path('tests/Fixtures/community/import-worker.php'), $tenant->id, (string) $user->id, 'confirm', $preview->token], base_path(), $environment),
        new Process([PHP_BINARY, base_path('tests/Fixtures/community/import-worker.php'), $tenant->id, (string) $user->id, ...$arguments], base_path(), $environment),
    ];
    $connection = DB::connection('landlord');
    $connection->beginTransaction();
    $connection->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['community.members:'.$tenant->id]);
    try {
        foreach ($workers as $worker) {
            $worker->start();
        }
        $deadline = microtime(true) + 15;
        do {
            $ready = count(array_filter($workers, fn (Process $worker): bool => str_contains($worker->getOutput(), 'READY')));
            usleep(20000);
        } while ($ready < 2 && microtime(true) < $deadline);
        expect($ready)->toBe(2, implode(' | ', array_map(fn (Process $worker): string => $worker->getErrorOutput().$worker->getOutput(), $workers)));
        $deadline = microtime(true) + 10;
        $expected = $race === 'same-preview' ? 1 : 2;
        do {
            $connection->select('select pg_stat_clear_snapshot()');
            $waiting = (int) $connection->selectOne("select count(*) as waiting from pg_stat_activity where datname = current_database() and wait_event = 'advisory'")->waiting;
            usleep(20000);
        } while ($waiting < $expected && microtime(true) < $deadline);
        expect($waiting)->toBeGreaterThanOrEqual($expected, implode(' | ', array_map(fn (Process $worker): string => $worker->getErrorOutput().$worker->getOutput(), $workers)));
        $connection->commit();
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
        }
        expect(Member::query()->where('tenant_id', $tenant->id)->count())->toBe(1);
        $output = implode("\n", array_map(fn (Process $worker): string => $worker->getOutput(), $workers));
        if ($race === 'same-preview') {
            expect($output)->toContain('This import has already been used');
        } elseif ($race === 'cap') {
            expect($output)->toContain('Free plan includes 1 members');
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
        $tenant->delete();
        $user->delete();
    }
})->with(['same-preview', 'same-person', 'cap']);
