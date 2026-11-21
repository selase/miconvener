<?php

declare(strict_types=1);

use App\Contracts\Secrets\SecretsProvider;
use App\Jobs\Middleware\TenantAwareJob;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    config(['filesystems.default' => 'local']);
    $this->neutralDisk = config('filesystems.disks.tenant');
    $this->logContexts = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event): void {
        if ($event->message === 'worker-isolation-probe') {
            $this->logContexts[] = $event->context;
        }
    });
});

function workerBoundaryJob(array $payload): Job
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn($payload);

    return $job;
}

function assertWorkerBoundary(?Tenant $tenant, array $neutralDisk): void
{
    expect(app(TenantContext::class)->activeTenantId())->toBe($tenant?->id)
        ->and(getPermissionsTeamId())->toBe($tenant?->id)
        ->and(URL::getDefaultParameters()['subdomain'] ?? null)->toBe($tenant?->slug);

    if ($tenant === null) {
        expect(config('database.connections.tenant'))->toBe(config('database.connections.landlord'))
            ->and(config('filesystems.disks.tenant'))->toBe($neutralDisk);
    } else {
        expect(config('filesystems.disks.tenant.root'))->toEndWith('/tenants/'.$tenant->id)
            ->and(Storage::disk('tenant')->path('probe'))->toEndWith('/tenants/'.$tenant->id.'/probe');

        if ($tenant->isolation_mode === 'shared') {
            expect(config('database.connections.tenant'))->toBe(config('database.connections.landlord'));
        }
    }

    Log::info('worker-isolation-probe');
}

test('persistent worker isolates A tenantless missing and B attempts including failures', function (bool $fails): void {
    $a = Tenant::factory()->create(['isolation_mode' => 'db_per_tenant', 'meta' => ['database' => 'worker_a_only']]);
    $b = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $user = User::factory()->create();
    Auth::guard()->setUser($user);

    foreach ([$a, null, '00000000-0000-0000-0000-000000000001', $b] as $selection) {
        $job = workerBoundaryJob($selection === null ? [] : ['tenant_id' => $selection instanceof Tenant ? $selection->id : $selection]);
        $exception = null;
        $ran = false;
        $user->setRelation('roles', collect())->setRelation('permissions', collect());

        try {
            Event::dispatch(new JobProcessing('database', $job));
            $ran = true;
            expect($user->relationLoaded('roles'))->toBeFalse()
                ->and($user->relationLoaded('permissions'))->toBeFalse();
            assertWorkerBoundary($selection, $this->neutralDisk);

            if ($selection === $a) {
                expect(config('database.connections.tenant.database'))->toBe('worker_a_only');
                Storage::disk('tenant');
            }

            if ($fails) {
                throw new RuntimeException('job failed');
            }
        } catch (Throwable $caught) {
            $exception = $caught;
        } finally {
            Event::dispatch(new JobAttempted('database', $job, $exception));
        }

        if (is_string($selection)) {
            expect($ran)->toBeFalse()->and($exception)->toBeInstanceOf(Illuminate\Database\Eloquent\ModelNotFoundException::class);
        } elseif ($exception !== null && (! $fails || $exception->getMessage() !== 'job failed')) {
            throw $exception;
        }

        assertWorkerBoundary(null, $this->neutralDisk);
        expect($this->logContexts[array_key_last($this->logContexts)]['tenant_id'] ?? null)->toBeNull();
    }
})->with([false, true]);

test('tenantless worker entry clears inherited tenant state before executing', function (): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    app(TenantContext::class)->setTenant($tenant);
    setPermissionsTeamId($tenant->id);
    config(['database.connections.tenant.database' => 'stale_database', 'filesystems.disks.tenant.root' => '/stale/tenant']);
    URL::defaults(['subdomain' => $tenant->slug]);
    Log::shareContext(['tenant_id' => $tenant->id, 'trace_id' => 'keep-trace']);

    $job = workerBoundaryJob([]);
    Event::dispatch(new JobProcessing('database', $job));
    try {
        assertWorkerBoundary(null, $this->neutralDisk);
        expect($this->logContexts[array_key_last($this->logContexts)])->not->toHaveKey('tenant_id')
            ->toHaveKey('trace_id', 'keep-trace');
    } finally {
        Event::dispatch(new JobAttempted('database', $job));
    }
});

test('reusing an authenticated user reloads team roles at every worker boundary', function (): void {
    $a = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $b = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $role = Role::firstOrCreate(['name' => 'Org Admin', 'guard_name' => 'web', 'tenant_id' => null]);
    $user = User::factory()->create();
    setPermissionsTeamId($a->id);
    $user->assignRole($role);
    $user->load('roles', 'permissions');
    Auth::guard()->setUser($user);

    foreach ([$a, $b, $a] as $tenant) {
        $job = workerBoundaryJob(['tenant_id' => $tenant->id]);
        Event::dispatch(new JobProcessing('database', $job));
        try {
            expect($user->hasRole('Org Admin'))->toBe($tenant === $a);
        } finally {
            Event::dispatch(new JobAttempted('database', $job));
        }

        expect($user->relationLoaded('roles'))->toBeFalse()
            ->and($user->relationLoaded('permissions'))->toBeFalse();
    }
});

test('an invalid declared tenant fails closed after resetting worker state', function (mixed $tenantId): void {
    $job = workerBoundaryJob(['tenant_id' => $tenantId]);
    expect(fn () => Event::dispatch(new JobProcessing('database', $job)))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
    assertWorkerBoundary(null, $this->neutralDisk);
    Event::dispatch(new JobAttempted('database', $job));
    expect(app(TenantAwareJob::class)->hasActiveScope())->toBeFalse();
})->with(['empty' => [''], 'array' => [[]], 'boolean' => [false]]);

test('nested missing queue tenants stay neutral during failure handling and then restore their parent', function (): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $parent = workerBoundaryJob(['tenant_id' => $tenant->id]);
    $child = workerBoundaryJob(['tenant_id' => '00000000-0000-0000-0000-000000000001']);
    Event::dispatch(new JobProcessing('database', $parent));
    try {
        expect(fn () => Event::dispatch(new JobProcessing('sync', $child)))
            ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
        assertWorkerBoundary(null, $this->neutralDisk);
        Event::dispatch(new JobAttempted('sync', $child));
        assertWorkerBoundary($tenant, $this->neutralDisk);
    } finally {
        Event::dispatch(new JobAttempted('sync', $child));
        Event::dispatch(new JobAttempted('database', $parent));
    }
    assertWorkerBoundary(null, $this->neutralDisk);
});

test('middleware restores its caller on success failure and missing tenant', function (string $child): void {
    $a = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $b = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $parent = workerBoundaryJob(['tenant_id' => $a->id]);
    Event::dispatch(new JobProcessing('database', $parent));

    try {
        $disk = Storage::disk('tenant');
        $connection = DB::connection('tenant');
        $pdo = DB::connection('landlord')->getPdo();
        $connection->setPdo($pdo);
        $childId = match ($child) {
            'tenantless' => null,
            'missing' => '00000000-0000-0000-0000-000000000001',
            default => $b->id,
        };
        $job = new class($childId)
        {
            public function __construct(public ?string $tenantId) {}
        };

        try {
            (new TenantAwareJob)->handle($job, function () use ($b, $child): void {
                assertWorkerBoundary($child === 'tenantless' ? null : $b, $this->neutralDisk);
                if ($child === 'failure') {
                    throw new RuntimeException('child failed');
                }
            });
            expect($child)->not->toBe('missing');
        } catch (Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            expect($child)->toBe('missing');
        } catch (RuntimeException $exception) {
            expect($child)->toBe('failure')->and($exception->getMessage())->toBe('child failed');
        }

        assertWorkerBoundary($a, $this->neutralDisk);
        expect(Storage::disk('tenant'))->toBe($disk)
            ->and(DB::connection('tenant'))->toBe($connection)
            ->and($connection->getRawPdo())->toBe($pdo)
            ->and($this->logContexts[array_key_last($this->logContexts)]['tenant_id'])->toBe($a->id);
    } finally {
        Event::dispatch(new JobAttempted('database', $parent));
    }
    assertWorkerBoundary(null, $this->neutralDisk);
})->with(['success', 'failure', 'tenantless', 'missing']);

test('a nested sync queue attempt restores its worker parent once', function (bool $fails): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $parent = workerBoundaryJob(['tenant_id' => $tenant->id]);
    Event::dispatch(new JobProcessing('database', $parent));

    try {
        $disk = Storage::disk('tenant');
        $ran = false;
        $failed = false;
        WorkerBoundarySyncProbe::$callback = function () use ($tenant, $fails, &$ran): void {
            $ran = true;
            assertWorkerBoundary($tenant, $this->neutralDisk);
            if ($fails) {
                throw new RuntimeException('sync failed');
            }
        };
        WorkerBoundarySyncProbe::$failureCallback = function () use ($tenant, $disk, &$failed): void {
            $failed = true;
            assertWorkerBoundary($tenant, $this->neutralDisk);
            expect(Storage::disk('tenant'))->not->toBe($disk);
        };

        try {
            Queue::connection('sync')->push(new WorkerBoundarySyncProbe);
        } catch (RuntimeException $exception) {
            expect($fails)->toBeTrue()->and($exception->getMessage())->toBe('sync failed');
        }

        assertWorkerBoundary($tenant, $this->neutralDisk);
        expect(Storage::disk('tenant'))->toBe($disk)
            ->and($ran)->toBeTrue()
            ->and($failed)->toBe($fails);
    } finally {
        WorkerBoundarySyncProbe::$callback = null;
        WorkerBoundarySyncProbe::$failureCallback = null;
        Event::dispatch(new JobAttempted('database', $parent));
    }
    assertWorkerBoundary(null, $this->neutralDisk);
})->with([false, true]);

test('synchronous dispatch outside a worker preserves the callers open tenant transaction', function (): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    app(TenantContext::class)->setTenant($tenant);
    setPermissionsTeamId($tenant->id);
    app(App\Services\Tenancy\TenantDatabaseManager::class)->configureShared();
    app(App\Services\Tenancy\TenantStorageManager::class)->configure($tenant);
    URL::defaults(['subdomain' => $tenant->slug, 'locale' => 'en']);
    $disk = Storage::disk('tenant');
    $connection = DB::connection('tenant');
    $connection->beginTransaction();
    $pdo = $connection->getPdo();

    try {
        WorkerBoundarySyncProbe::$callback = function () use ($tenant): void {
            assertWorkerBoundary($tenant, $this->neutralDisk);
            URL::defaults(['subdomain' => 'child-mutation', 'locale' => 'fr']);
        };
        Queue::connection('sync')->push(new WorkerBoundarySyncProbe);

        assertWorkerBoundary($tenant, $this->neutralDisk);
        expect(Storage::disk('tenant'))->toBe($disk)
            ->and(DB::connection('tenant'))->toBe($connection)
            ->and($connection->transactionLevel())->toBe(1)
            ->and($connection->getRawPdo())->toBe($pdo)
            ->and($pdo->inTransaction())->toBeTrue()
            ->and(URL::getDefaultParameters()['locale'])->toBe('en');
    } finally {
        WorkerBoundarySyncProbe::$callback = null;
        $connection->rollBack();
    }
});

test('partially configured tenant setup unwinds before the handler runs', function (): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared', 's3_mode' => 'byo', 's3_secret_ref' => 'unavailable-worker-storage']);
    $this->mock(SecretsProvider::class, function ($mock): void {
        $mock->shouldReceive('getSecret')->with('unavailable-worker-storage')->andThrow(new RuntimeException('storage setup failed'));
    });

    $job = workerBoundaryJob(['tenant_id' => $tenant->id]);
    expect(fn () => Event::dispatch(new JobProcessing('database', $job)))
        ->toThrow(RuntimeException::class, 'storage setup failed');
    assertWorkerBoundary(null, $this->neutralDisk);
    Event::dispatch(new JobAttempted('database', $job));
});

test('usage recording failure still restores middleware caller and clears loaded grants', function (): void {
    app(App\Services\Tenancy\TenantDatabaseManager::class)->configureShared();
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $user = User::factory()->create();
    Auth::guard()->setUser($user);
    app()->bind(App\Services\Tenancy\UsageService::class, function (): never {
        throw new RuntimeException('usage unavailable');
    });
    $job = new class($tenant->id)
    {
        public function __construct(public string $tenantId) {}
    };

    expect(fn () => (new TenantAwareJob)->handle($job, function () use ($user): void {
        $user->setRelation('roles', collect())->setRelation('permissions', collect());
    }))->toThrow(RuntimeException::class, 'usage unavailable');

    expect($user->relationLoaded('roles'))->toBeFalse()
        ->and($user->relationLoaded('permissions'))->toBeFalse();
    assertWorkerBoundary(null, $this->neutralDisk);
});

test('platform dashboard access revokes immediately even with a warm legacy cache and loaded roles', function (): void {
    $user = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'Superadmin', 'guard_name' => 'web', 'tenant_id' => null]);
    setPermissionsTeamId(null);
    $user->assignRole($role);
    $user->load('roles', 'permissions');
    expect(Gate::forUser($user)->allows('access-superadmin-dashboard'))->toBeTrue();
    Cache::put("user_{$user->id}_is_superadmin", true, 3600);
    $user->roles()->detach($role);

    expect(Gate::forUser($user)->allows('access-superadmin-dashboard'))->toBeFalse();
});

final class WorkerBoundarySyncProbe implements ShouldQueue
{
    public static ?Closure $callback = null;

    public static ?Closure $failureCallback = null;

    public function handle(): void
    {
        (self::$callback)();
    }

    public function failed(?Throwable $exception): void
    {
        if (self::$failureCallback !== null) {
            (self::$failureCallback)();
        }
    }
}
