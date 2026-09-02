<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

final class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        \App\Models\UserLoginHistory::class => \App\Policies\UserLoginHistoryPolicy::class,
    ];

    public function register(): void
    {
        parent::register();

        \Illuminate\Support\Facades\Gate::before(function ($user, $ability): ?bool {
            // Superadmins bypass everything
            if (method_exists($user, 'isGlobalSuperAdmin') && $user->isGlobalSuperAdmin()) {
                return true;
            }

            // Entitlement Bridge: Check if the tenant is entitled to this permission
            /** @var \App\Services\Tenancy\EntitlementService $entitlementService */
            $entitlementService = app(\App\Services\Tenancy\EntitlementService::class);
            if (! $entitlementService->isEntitled($ability)) {
                return false;
            }

            return null;
        });
    }

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        \Illuminate\Support\Facades\Gate::define('access-superadmin-dashboard', fn($user) => \Illuminate\Support\Facades\Cache::remember("user_{$user->id}_is_superadmin", 3600, fn() => \Illuminate\Support\Facades\DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.model_type', $user::class)
            ->where('roles.name', \App\Models\Role::SYSTEM_ROLES[0]) // 'Superadmin'
            ->whereNull('model_has_roles.tenant_id')
            ->exists()));
    }
}
