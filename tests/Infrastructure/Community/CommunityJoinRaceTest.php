<?php

declare(strict_types=1);
use App\Models\CommunityJoinRequest;
use App\Models\CommunitySettings;
use App\Models\Member;
use App\Models\MembershipType;
use App\Services\Community\MembershipJoining;
use App\Services\Events\PlatformAttendeeVerification;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(DatabaseTruncation::class);
afterEach(function (): void {
    $this->truncateDatabaseTables();
});
it('serialises the final slot duplicate joins and duplicate approval across actual workers', function (string $mode): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    [$tenant,$owner] = eventHost('join-race');
    Artisan::call('community:enable', ['tenant' => $tenant->slug]);
    $tenant->features()->create(['feature_key' => 'community_members', 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => 1]]);
    $type = MembershipType::factory()->create(['tenant_id' => $tenant->id]);
    CommunitySettings::for($tenant)->fill(['public_join_enabled' => true, 'public_membership_type_id' => $type->id])->save();
    $key = (string) Str::uuid();
    if (in_array($mode, ['approve', 'approve_phone'], true)) {
        $session = app('session.store');
        $session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => 'join@example.test', 'expires_at' => now()->addHour()->timestamp]);
        if ($mode === 'approve_phone') {
            $session->put(App\Services\Community\PhoneVerification::SESSION_KEY, ['phone' => '+233241234567', 'expires_at' => now()->addHour()->timestamp]);
        }
        $key = app(MembershipJoining::class)->submit($tenant, $session, ['name' => 'Applicant', 'channel' => $mode === 'approve_phone' ? 'sms' : 'email'], $key)->id;
    }
    $environment = ['DATABASE_URL' => '', 'DB_DATABASE' => (string) config('database.connections.landlord.database'), 'DB_HOST' => (string) config('database.connections.landlord.host'), 'APP_ENV' => 'testing', 'TELESCOPE_ENABLED' => 'false', 'PAO_DISABLE' => '1'];
    $workers = [];
    foreach ([0, 1] as $index) {
        $workers[] = new Process([PHP_BINARY, base_path('tests/Fixtures/community/joining-worker.php'), $tenant->id, $mode === 'approve_phone' ? 'approve' : $mode, $mode === 'last_slot' ? 'join'.$index.'@example.test' : 'join@example.test', $mode === 'last_slot' ? (string) Str::uuid() : $key, (string) $owner->id], base_path(), $environment);
    }
    $connection = DB::connection('landlord');
    $connection->beginTransaction();
    $connection->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['community.members:'.$tenant->id]);
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
        $output = '';
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput().$worker->getOutput());
            expect($worker->getOutput())->toMatch('/DONE|CAP_REACHED/');
            $output .= $worker->getOutput();
        }
        expect(Member::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1)
            ->and(CommunityJoinRequest::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);
        if ($mode === 'last_slot') {
            expect(mb_substr_count($output, 'CAP_REACHED'))->toBe(1);
        } else {
            expect($output)->not->toContain('CAP_REACHED');
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
})->with(['last_slot', 'duplicate', 'approve', 'approve_phone']);
