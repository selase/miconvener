<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\UsersController;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\OrganisationInvitations;
use App\Services\Tenancy\OrganisationMemberships;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantDatabaseManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 3).'/vendor/autoload.php';

if (getenv('APP_ENV') !== 'testing' || ! Tests\Support\IsolatedPostgresDatabase::permits((string) getenv('DB_DATABASE'))) {
    fwrite(STDERR, "Membership workers require APP_ENV=testing and an isolated testing database.\n");
    exit(1);
}

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $connection = DB::connection('landlord');
    if (! app()->environment('testing') || $connection->getDriverName() !== 'pgsql'
        || ! Tests\Support\IsolatedPostgresDatabase::permits((string) $connection->getDatabaseName())
        || ! Tests\Support\IsolatedPostgresDatabase::permits((string) $connection->selectOne('select current_database() as name')->name)) {
        throw new RuntimeException('Refusing a membership worker outside isolated PostgreSQL testing.');
    }
    config(['cache.default' => 'array', 'mail.default' => 'array', 'queue.default' => 'membership-race',
        'queue.connections.membership-race' => ['driver' => 'database', 'connection' => 'landlord', 'table' => 'jobs', 'queue' => 'membership-race', 'retry_after' => 90, 'after_commit' => true]]);
    $input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    $connection->select("select set_config('application_name', ?, false)", [$input['application_name']]);
    $connection->statement("SET lock_timeout = '30s'");
    $connection->statement("SET statement_timeout = '45s'");
    $tenant = Tenant::query()->findOrFail($input['tenant']);
    app(TenantDatabaseManager::class)->configureShared();
    app(TenantContext::class)->setTenant($tenant);
    setPermissionsTeamId($tenant->id);
    $actor = User::query()->findOrFail($input['actor']);
    $subject = isset($input['subject']) ? User::query()->findOrFail($input['subject']) : null;
    $role = Role::query()->findOrFail($input['role']);
    $actor->load(['roles.permissions', 'permissions']);
    $subject?->load(['roles.permissions', 'permissions']);
    $pid = (int) $connection->selectOne('select pg_backend_pid() as pid')->pid;
    $result = ['pid' => $pid, 'outcome' => 'OK'];
    if (in_array($input['action'], ['admit', 'delete_identity'], true)) {
        $subject->load('allTenants');
        $result['preloaded_membership_count'] = $subject->allTenants->count();
    }
    try {
        $memberships = app(OrganisationMemberships::class);
        switch ($input['action']) {
            case 'disable_mfa':
                $request = App\Http\Requests\Tenant\DisableTwoFactorRequest::create('/account/two-factor', 'DELETE', ['password' => 'password']);
                $request->setUserResolver(fn () => $actor);
                $request->setValidator(validator(['password' => 'password'], ['password' => 'required']));
                $request->setLaravelSession(app('session')->driver());
                app(App\Http\Controllers\Tenant\AccountController::class)->disable($request);
                break;
            case 'admit':
                $memberships->admit($tenant, $actor, $subject, $role, []);
                break;
            case 'delete_identity':
                setPermissionsTeamId(null);
                Auth::setUser($actor);
                app(UsersController::class)->destroy($subject->uuid);
                break;
            case 'revoke':
                $memberships->revoke($tenant, $actor, $subject);
                break;
            case 'update':
                $memberships->updateAccess($tenant, $actor, $subject, $role, [], $input['status']);
                break;
            case 'invite':
                $invitation = app(OrganisationInvitations::class)->invite($tenant, $actor, $input['email'], $role, []);
                $result['invitation'] = $invitation->id;
                break;
            default:
                throw new InvalidArgumentException('Unknown membership worker action.');
        }
    } catch (ModelNotFoundException $exception) {
        if ($input['action'] !== 'admit') {
            throw $exception;
        }
        $result['outcome'] = 'NOT_FOUND';
        $result['missing_model'] = $exception->getModel();
    } catch (ValidationException $exception) {
        $result['outcome'] = 'VALIDATION';
        $result['errors'] = $exception->errors();
    } catch (HttpExceptionInterface $exception) {
        if ($exception->getStatusCode() !== 403) {
            throw $exception;
        }
        $result['outcome'] = 'FORBIDDEN';
    }
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    foreach ($exception->getTrace() as $frame) {
        fwrite(STDERR, ($frame['file'] ?? '[internal]').':'.($frame['line'] ?? 0).' '.($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'].PHP_EOL);
    }
    exit(1);
}
