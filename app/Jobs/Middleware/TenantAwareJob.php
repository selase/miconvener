<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantDatabaseManager;
use App\Services\Tenancy\TenantStorageManager;
use App\Services\Tenancy\UsageService;
use BackedEnum;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Throwable;

final class TenantAwareJob
{
    private int $depth = 0;

    /** @var array<string, mixed> */
    private readonly array $neutralDisk;

    public function __construct()
    {
        $this->neutralDisk = config('filesystems.disks.tenant', []);
    }

    public function hasActiveScope(): bool
    {
        return $this->depth > 0;
    }

    /**
     * Return an idempotent cleanup for queue attempts and synchronous callers.
     * Direct setup failures unwind immediately. Queue attempts register their
     * cleanup first so failed callbacks stay neutral until JobAttempted.
     */
    public function enter(mixed $tenantId, bool $restoreParent, ?Closure $registerCleanup = null): Closure
    {
        $context = app(TenantContext::class);
        $parentTenant = $context->getTenant();
        $parentId = $context->activeTenantId();
        $parentTeam = getPermissionsTeamId();
        $parentDatabase = app('db');
        $parentResolver = Model::getConnectionResolver();
        $parentDatabaseConfig = config('database.connections.tenant');
        $parentFilesystem = app('filesystem');
        $parentDiskConfig = config('filesystems.disks.tenant');
        $parentUrl = URL::getFacadeRoot();
        $parentLogTenant = $parentId ?? (Log::sharedContext()['tenant_id'] ?? null);

        $this->depth++;
        $closed = false;
        $registered = false;
        $cleanup = function () use (&$closed, $restoreParent, $context, $parentTenant, $parentId, $parentTeam, $parentDatabase, $parentResolver, $parentDatabaseConfig, $parentFilesystem, $parentDiskConfig, $parentUrl, $parentLogTenant): void {
            if ($closed) {
                return;
            }

            $closed = true;
            $this->depth--;

            try {
                app(TenantDatabaseManager::class)->configureShared();
            } finally {
                if ($restoreParent) {
                    DB::swap($parentDatabase);
                    Model::setConnectionResolver($parentResolver);
                    config(['database.connections.tenant' => $parentDatabaseConfig]);
                }

                $context->clear();
                if ($restoreParent && $parentTenant instanceof Tenant) {
                    $context->setTenant($parentTenant);
                }
                if ($restoreParent) {
                    $context->setActiveTenantId($parentId);
                }
                setPermissionsTeamId($restoreParent ? $parentTeam : null);
                $this->clearUserPermissions();

                config(['filesystems.disks.tenant' => $restoreParent ? $parentDiskConfig : $this->neutralDisk]);
                Storage::swap($parentFilesystem);
                if (! $restoreParent) {
                    $parentFilesystem->forgetDisk('tenant');
                    $parentUrl->defaults(['subdomain' => null]);
                }
                URL::swap($parentUrl);
                $this->setLogTenant($restoreParent ? $parentLogTenant : null);
            }
        };

        try {
            if ($restoreParent) {
                /*
                 * Purging a cached connection disconnects it and resets its
                 * transaction depth. Give the child its own tenant connection;
                 * landlord and other connections retain their original manager.
                 */
                $database = new class($parentDatabase) extends DatabaseManager
                {
                    public function __construct(private readonly DatabaseManager $parent)
                    {
                        parent::__construct(app(), app('db.factory'));
                    }

                    public function connection($name = null): Connection
                    {
                        $name = $name instanceof BackedEnum ? $name->value : ($name ?? $this->getDefaultConnection());
                        [$database] = $this->parseConnectionName($name);

                        return $database === 'tenant'
                            ? parent::connection($name)
                            : $this->parent->connection($name);
                    }
                };
                DB::swap($database);
                Model::setConnectionResolver($database);
            }

            $filesystem = clone $parentFilesystem;
            $filesystem->forgetDisk('tenant');
            Storage::swap($filesystem);
            config(['filesystems.disks.tenant' => $this->neutralDisk]);

            $url = clone $parentUrl;
            $url->setRequest($url->getRequest());
            $url->defaults(['subdomain' => null]);
            URL::swap($url);

            $this->reset();

            if ($registerCleanup !== null) {
                $registerCleanup($cleanup);
                $registered = true;
            }

            if ($tenantId !== null) {
                if (! is_string($tenantId) || $tenantId === '') {
                    throw (new ModelNotFoundException)->setModel(Tenant::class);
                }

                $tenant = Tenant::query()->findOrFail($tenantId);
                $context->setTenant($tenant);
                setPermissionsTeamId($tenant->id);
                app(TenantDatabaseManager::class)->configure($tenant);
                app(TenantStorageManager::class)->configure($tenant);
                URL::defaults(['subdomain' => $tenant->slug]);
                $this->setLogTenant((string) $tenant->id);
            }
        } catch (Throwable $exception) {
            if ($registered) {
                $this->reset();
            } else {
                $cleanup();
            }

            throw $exception;
        }

        return $cleanup;
    }

    public function handle(object $job, Closure $next): void
    {
        $cleanup = app(self::class)->enter($job->tenantId ?? null, true);
        $tenant = app(TenantContext::class)->getTenant();
        $start = microtime(true);
        $exception = null;

        try {
            $next($job);
        } catch (Throwable $caught) {
            $exception = $caught;
            throw $caught;
        } finally {
            try {
                if ($tenant instanceof Tenant) {
                    app(UsageService::class)->recordJob(
                        $tenant,
                        $job::class,
                        ! $exception instanceof Throwable,
                        (int) ((microtime(true) - $start) * 1000)
                    );
                }
            } finally {
                $cleanup();
            }
        }
    }

    private function reset(): void
    {
        app(TenantContext::class)->clear();
        setPermissionsTeamId(null);
        $this->clearUserPermissions();
        $this->setLogTenant(null);
        URL::defaults(['subdomain' => null]);
        config(['filesystems.disks.tenant' => $this->neutralDisk]);
        Storage::forgetDisk('tenant');
        app(TenantDatabaseManager::class)->configureShared();
    }

    private function clearUserPermissions(): void
    {
        $guard = Auth::guard();
        if ($guard->hasUser()) {
            $user = $guard->user();
            if ($user instanceof Model) {
                $user->unsetRelation('roles')->unsetRelation('permissions');
            }
        }
    }

    private function setLogTenant(?string $tenantId): void
    {
        $shared = Log::sharedContext();
        unset($shared['tenant_id']);
        Log::withoutContext(['tenant_id']);
        Log::flushSharedContext();
        Log::shareContext($tenantId === null ? $shared : [...$shared, 'tenant_id' => $tenantId]);
    }
}
