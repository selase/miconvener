<?php

declare(strict_types=1);

use App\Models\OrganisationInvitation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Tenancy\OrganisationInvitations;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process;

trait CleansOrganisationInvitationRaceDatabase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = ['landlord'];

    protected function beforeTruncatingDatabase(): void
    {
        $connection = DB::connection('landlord');
        if (! app()->environment('testing') || $connection->getDriverName() !== 'pgsql'
            || ! Tests\Support\IsolatedPostgresDatabase::permits((string) $connection->getDatabaseName())
            || $connection->selectOne('select current_database() as name')->name !== $connection->getDatabaseName()) {
            throw new RuntimeException('Invitation concurrency tests require the configured isolated PostgreSQL testing database.');
        }
    }
}

uses(CleansOrganisationInvitationRaceDatabase::class);

afterEach(function (): void {
    $this->truncateDatabaseTables();
});

test('concurrent accepts wait on the tenant lock and consume the invitation exactly once', function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Mail::fake();
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $owner = User::factory()->create();
    $subject = User::factory()->create();
    $tenant->users()->attach($owner->id);
    setPermissionsTeamId($tenant->id);
    $owner->assignRole('Org Superadmin');
    $role = Role::query()->whereNull('tenant_id')->where('name', 'Org Admin')->firstOrFail();
    $invitation = app(OrganisationInvitations::class)->invite($tenant, $owner, $subject->email, $role, []);
    $token = Crypt::decryptString($invitation->delivery_payload);
    $connection = DB::connection('landlord');
    $database = $connection->getDatabaseName();
    $code = <<<'WORKER'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
config(['database.default' => 'landlord', 'database.connections.landlord' => $input['connection'], 'database.connections.tenant' => $input['connection'], 'cache.default' => 'array', 'organisation_access.enabled' => true]);
Illuminate\Support\Facades\DB::purge('landlord');
Illuminate\Support\Facades\DB::purge('tenant');
$db = Illuminate\Support\Facades\DB::connection('landlord');
if (! app()->environment('testing') || ! \Tests\Support\IsolatedPostgresDatabase::permits($db->getDatabaseName()) || $db->selectOne('select current_database() as name')->name !== $input['database']) {
    exit(2);
}
$db->selectOne("select set_config('application_name', ?, false)", [$input['name']]);
try {
    $actor = App\Models\User::query()->findOrFail($input['subject']);
    app(App\Services\Tenancy\OrganisationInvitations::class)->accept($input['token'], $actor);
    echo json_encode(['outcome' => 'accepted']);
} catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) {
    echo json_encode(['outcome' => 'denied', 'status' => $exception->getStatusCode()]);
} catch (Throwable $exception) {
    echo json_encode(['outcome' => 'error', 'class' => $exception::class]);
    exit(1);
}
WORKER;
    $workers = [];
    try {
        $connection->beginTransaction();
        Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
        foreach ([1, 2] as $index) {
            $name = 'invitation-accept-'.bin2hex(random_bytes(5));
            $worker = new Process([PHP_BINARY, '-r', $code], base_path(), ['APP_ENV' => 'testing', 'DATABASE_URL' => '', 'DB_DATABASE' => $database]);
            $worker->setInput(json_encode(['connection' => config('database.connections.landlord'), 'database' => $database, 'subject' => $subject->id, 'token' => $token, 'name' => $name], JSON_THROW_ON_ERROR));
            $worker->setTimeout(30);
            $worker->start();
            $workers[] = $worker;
            $deadline = microtime(true) + 15;
            do {
                $connection->select('select pg_stat_clear_snapshot()');
                $waiting = $connection->selectOne("select query from pg_stat_activity where datname = current_database() and application_name = ? and wait_event_type = 'Lock' and cardinality(pg_blocking_pids(pid)) > 0", [$name]);
                if ($waiting || ! $worker->isRunning()) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            expect($waiting)->not->toBeNull('Acceptance worker did not wait for the tenant lock.');
            expect(mb_strtolower($waiting->query))->toContain('"tenants"', 'for update');
        }
        $connection->commit();
        $outcomes = [];
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput().$worker->getOutput());
            $result = json_decode($worker->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            $outcomes[] = $result['outcome'];
        }
        sort($outcomes);
        expect($outcomes)->toBe(['accepted', 'denied'])
            ->and(OrganisationInvitation::query()->findOrFail($invitation->id)->status)->toBe('accepted')
            ->and(TenantMembership::query()->where('tenant_id', $tenant->id)->where('user_id', $subject->id)->where('status', 'active')->count())->toBe(1);
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }
});
