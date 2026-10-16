<?php

declare(strict_types=1);

use App\Models\PlatformPhoneAccessCode;
use App\Models\PlatformPhoneSendReservation;
use App\Services\Community\PhoneVerification;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(DatabaseTruncation::class);

afterEach(function (): void {
    $this->truncateDatabaseTables();
});

it('serialises concurrent phone budget and code consumption', function (string $mode): void {
    $phone = '+233241234567';
    if ($mode === 'confirm') {
        PlatformPhoneAccessCode::factory()->create(['phone' => $phone, 'phone_hash' => PhoneVerification::digest($phone), 'code_hash' => Hash::make('123456')]);
    }
    $environment = ['DATABASE_URL' => '', 'DB_DATABASE' => (string) config('database.connections.landlord.database'), 'DB_HOST' => (string) config('database.connections.landlord.host'), 'APP_ENV' => 'testing', 'TELESCOPE_ENABLED' => 'false', 'PAO_DISABLE' => '1', 'BCRYPT_ROUNDS' => '4'];
    $workers = array_map(fn () => new Process([PHP_BINARY, base_path('tests/Fixtures/community/phone-verification-worker.php'), $mode, $mode === 'budget' ? (string) Str::uuid() : $phone], base_path(), $environment), [1, 2]);
    $connection = DB::connection('landlord');
    $connection->beginTransaction();
    $lock = $mode === 'budget' ? 'phone-signin-budget:'.now('UTC')->toDateString() : 'phone-signin-number:'.PhoneVerification::digest($phone);
    $connection->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$lock]);
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
            $waiting = (int) $connection->selectOne("select count(*) as waiting from pg_stat_activity where datname=current_database() and wait_event='advisory'")->waiting;
            usleep(20000);
        } while ($waiting < 2 && microtime(true) < $deadline);
        expect($waiting)->toBeGreaterThanOrEqual(2);
        $connection->commit();
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput().$worker->getOutput());
            expect((bool) preg_match('/SUCCESS|DENIED|CHANGED/', $worker->getOutput()))->toBeTrue($worker->getErrorOutput().$worker->getOutput());
        }
        expect(count(array_filter($workers, fn ($worker) => str_contains($worker->getOutput(), 'SUCCESS'))))->toBe(1);
        if ($mode === 'budget') {
            expect(PlatformPhoneSendReservation::query()->count())->toBe(1);
        } else {
            expect(PlatformPhoneAccessCode::query()->whereNotNull('consumed_at')->count())->toBe(1);
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
})->with(['budget', 'confirm']);

it('waits for an ordinary in-flight session writer before removing persisted portal access', function (): void {
    $files = new Illuminate\Filesystem\Filesystem;
    $directory = sys_get_temp_dir().'/community-session-race-'.Str::uuid();
    $files->makeDirectory($directory, 0700, true);
    $handler = new Illuminate\Session\FileSessionHandler($files, $directory, 120);
    $id = str_repeat('a', 20).bin2hex(random_bytes(10));
    $session = new Illuminate\Session\Store('qa', $handler, $id);
    $session->start();
    $session->put(PhoneVerification::SESSION_KEY, ['phone' => '+233241234567', 'expires_at' => now()->addHour()->timestamp]);
    $session->save();
    $lock = Illuminate\Support\Facades\Cache::store(config('session.block_store') ?? 'file')->lock('session:'.$id, 600);
    expect($lock->get())->toBeTrue();
    $environment = ['DATABASE_URL' => '', 'DB_DATABASE' => (string) config('database.connections.landlord.database'), 'DB_HOST' => (string) config('database.connections.landlord.host'), 'CACHE_PREFIX' => Illuminate\Support\Facades\Cache::store(config('session.block_store'))->getStore()->getPrefix(), 'APP_ENV' => 'testing', 'PAO_DISABLE' => '1', 'TELESCOPE_ENABLED' => 'false'];
    $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/community/session-removal-worker.php'), $id, $directory], base_path(), $environment);
    try {
        $worker->start();
        $deadline = microtime(true) + 15;
        do {
            usleep(20000);
        } while (! str_contains($worker->getOutput(), 'READY') && $worker->isRunning() && microtime(true) < $deadline);
        expect($worker->getOutput())->toContain('READY');
        usleep(250000);
        expect($worker->getOutput())->not->toContain('ENTERED');
        $late = new Illuminate\Session\Store('qa', $handler, $id);
        $late->start();
        $late->put('ordinary_request', 'finished');
        $late->save();
        $lock->release();
        $worker->wait();
        expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput().$worker->getOutput());
        expect($worker->getOutput())->toContain('DONE');
        $replay = new Illuminate\Session\Store('qa', $handler, $id);
        $replay->start();
        expect($replay->has(PhoneVerification::SESSION_KEY))->toBeFalse();
    } finally {
        $lock->release();
        if ($worker->isRunning()) {
            $worker->stop();
        }$files->deleteDirectory($directory);
    }
});
