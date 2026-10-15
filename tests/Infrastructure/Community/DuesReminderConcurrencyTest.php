<?php

declare(strict_types=1);

use App\Models\CommunitySettings;
use App\Models\DuesCharge;
use App\Models\DuesReminder;
use App\Models\Member;
use App\Models\MembershipType;
use App\Models\TenantAddon;
use App\Models\TenantNotificationSetting;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

uses(DatabaseTruncation::class);

test('concurrent automatic and manual reminders respect daily credit and monthly reservations', function (string $constraint) {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    [$tenant, $user] = eventHost('reminder-race-'.bin2hex(random_bytes(4)));
    Artisan::call('community:enable', ['tenant' => $tenant->slug]);
    if ($constraint === 'reset') {
        $tenant->features()->create(['feature_key' => 'sms_credits', 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => 1]]);
    }
    $type = MembershipType::factory()->create(['tenant_id' => $tenant->id]);
    foreach (range(1, in_array($constraint, ['daily', 'reset'], true) ? 1 : 2) as $i) {
        $member = Member::factory()->create(['tenant_id' => $tenant->id, 'membership_type_id' => $type->id, 'email' => $constraint === 'daily' ? 'race@example.test' : null, 'phone' => '024123456'.$i]);
        DuesCharge::query()->create(['tenant_id' => $tenant->id, 'member_id' => $member->id, 'membership_type_id' => $type->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'due_on' => '2026-10-08', 'status' => 'open', 'amount' => 100, 'currency' => 'GHS']);
    }
    TenantNotificationSetting::forTenant($tenant->id)->update(['sms_enabled' => true]);
    $pack = TenantAddon::factory()->create(['tenant_id' => $tenant->id, 'addon_type' => TenantAddon::TYPE_SMS_PACK, 'quantity' => $constraint === 'credit' ? 1 : 10, 'billing_interval' => TenantAddon::INTERVAL_ONE_OFF, 'status' => TenantAddon::STATUS_ACTIVE]);
    CommunitySettings::for($tenant)->fill(['sms_monthly_limit' => $constraint === 'monthly' ? 1 : null])->save();
    $environment = ['DATABASE_URL' => '', 'DB_DATABASE' => (string) config('database.connections.landlord.database'), 'DB_HOST' => (string) config('database.connections.landlord.host'), 'APP_ENV' => 'testing', 'TELESCOPE_ENABLED' => 'false'];
    $workers = array_map(fn ($mode) => new Process([PHP_BINARY, base_path('tests/Fixtures/community/reminder-worker.php'), $tenant->id, (string) $user->id, $mode], base_path(), $environment), ['automatic', $constraint === 'reset' ? 'reset' : 'manual']);
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
        expect(DuesReminder::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', 'sent')->count())->toBe($constraint === 'daily' ? 2 : 1)
            ->and((int) ($pack->fresh()->meta['remaining'] ?? $pack->quantity))->toBe($constraint === 'reset' ? 10 : ($constraint === 'credit' ? 0 : 9));
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
})->with(['credit', 'monthly', 'daily', 'reset']);
