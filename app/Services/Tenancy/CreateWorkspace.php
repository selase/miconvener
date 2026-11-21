<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enum\TenantStatusEnum;
use App\Models\Package;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\SignupAlerts;
use App\Services\Pricing\GhanaEligibility;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

final class CreateWorkspace
{
    public function __construct(private readonly TenantProvisioner $provisioner, private readonly OrganisationMemberships $memberships) {}

    public function existing(User $user, string $key): ?Tenant
    {
        return Tenant::query()->where('meta->workspace_creation->user_id', (string) $user->id)
            ->where('meta->workspace_creation->request_key', $key)->first();
    }

    /** @param array{organization_name: string, slug: string, request_key: string, plan?: string} $input */
    public function create(User $user, array $input, string $currency): Tenant
    {
        abort_unless((bool) config('organisation_access.enabled', false), 503, 'Organisation creation temporarily unavailable');
        abort_unless($user->hasVerifiedEmail() && in_array($user->status ?? null, [null, User::STATUS_ACTIVE], true), 403);
        $store = Cache::store('portal_sessions')->getStore();
        if (! $store instanceof \Illuminate\Contracts\Cache\LockProvider) {
            throw new LogicException('Workspace creation requires a shared locking cache store.');
        }
        $lock = $store->lock('workspace-create:'.$user->id.':'.$input['request_key'], 600);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['request_key' => 'Your organisation is being prepared. Open your workspaces to check its progress.']);
        }

        try {
            $tenant = DB::connection('landlord')->transaction(function () use ($user, $input, $currency): Tenant {
                $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                abort_unless(in_array($lockedUser->status, [null, User::STATUS_ACTIVE], true), 403);
                $existing = $this->existing($user, $input['request_key']);
                if ($existing) {
                    if ($existing->name !== $input['organization_name'] || $existing->slug !== $input['slug']) {
                        throw ValidationException::withMessages(['request_key' => 'This request already belongs to another organisation. Return to your workspaces.']);
                    }

                    return $existing;
                }
                $package = Package::query()->where('is_free', true)->where('is_active', true)->orderBy('sort_order')->firstOrFail();
                Role::query()->where('name', 'Org Superadmin')->whereNull('tenant_id')->firstOrFail();

                return Tenant::query()->create([
                    'name' => $input['organization_name'], 'slug' => $input['slug'], 'email' => $user->email,
                    'status' => TenantStatusEnum::ACTIVE, 'isolation_mode' => 'shared', 'db_driver' => 'pgsql',
                    'package_id' => $package->id,
                    'pricing_region' => $currency === 'USD' ? GhanaEligibility::REGION_INTERNATIONAL : null,
                    'meta' => ['workspace_creation' => ['user_id' => (string) $user->id, 'request_key' => $input['request_key'], 'state' => 'pending']],
                ]);
            });
            if (($tenant->meta['workspace_creation']['state'] ?? null) === 'ready') {
                abort_unless($this->memberships->isActive($user, $tenant), 403);

                return $tenant;
            }
            /** Only shared signup infrastructure is replayable after interruption. */
            abort_unless($tenant->isolation_mode === 'shared', 409);
            abort_unless($tenant->status === TenantStatusEnum::ACTIVE, 403);
            $this->state($tenant, 'provisioning');
            try {
                $this->provisioner->provision($tenant);
                DB::connection('landlord')->transaction(function () use ($tenant, $user): void {
                    $currentTenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                    abort_unless($currentTenant->status === TenantStatusEnum::ACTIVE, 403);
                    abort_unless(($currentTenant->meta['workspace_creation']['state'] ?? null) === 'provisioning', 409);
                    $currentUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                    abort_unless($currentUser->hasVerifiedEmail() && in_array($currentUser->status, [null, User::STATUS_ACTIVE], true), 403);
                    $tenant->setRawAttributes($currentTenant->getAttributes(), true);
                    $this->memberships->inTenant($tenant, [$user], function () use ($tenant, $user): void {
                        if ($user->allTenants()->whereKey($tenant->id)->exists()) {
                            abort_unless($this->memberships->isActive($user, $tenant), 403);
                        } else {
                            $user->allTenants()->attach($tenant->id, ['accepted_at' => now()]);
                        }
                        $user->assignRole(Role::query()->where('name', 'Org Superadmin')->whereNull('tenant_id')->firstOrFail());
                    });
                    $this->state($tenant, 'ready');
                });
            } catch (Throwable $exception) {
                $this->state($tenant, 'failed');
                report($exception);
                throw ValidationException::withMessages(['request_key' => 'We could not finish preparing your organisation. Retry this request from your workspaces; your organisation has been saved.']);
            }
            app(SignupAlerts::class)->signedUp($tenant);

            return $tenant;
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505' && Tenant::query()->where('slug', $input['slug'])->exists()) {
                throw ValidationException::withMessages(['slug' => 'That address is already taken. Please choose another.']);
            }

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    private function state(Tenant $tenant, string $state): void
    {
        DB::connection('landlord')->transaction(function () use ($tenant, $state): void {
            $currentTenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $meta = $currentTenant->meta ?? [];
            if (($meta['workspace_creation']['state'] ?? null) !== 'ready' || $state === 'ready') {
                $meta['workspace_creation']['state'] = $state;
                $meta['workspace_creation']['updated_at'] = now()->toIso8601String();
                $currentTenant->forceFill(['meta' => $meta])->save();
            }
            $tenant->setRawAttributes($currentTenant->getAttributes(), true);
        });
    }
}
