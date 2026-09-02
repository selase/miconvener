<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Libraries\Helper;
use App\Models\User;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Spatie\Permission\Models\Role;

final class DashboardController extends Controller
{
    public function __invoke(\Illuminate\Http\Request $request, \App\Services\TenantStatsService $tenantStatsService): Factory|View
    {
        $this->authorize('access dashboard');

        $days = $request->integer('days', 7);
        // Ensure days is within reasonable bounds
        if (! in_array($days, [7, 30, 90])) {
            $days = 7;
        }

        $users = User::query()->latest()->limit(8)->get();
        $roleCount = Role::query()
            ->select('roles.*')
            ->selectSub(function ($query): void {
                $query->from(config('permission.table_names.model_has_roles'))
                    ->whereColumn(config('permission.column_names.role_pivot_key') ?? 'role_id', 'roles.id')
                    ->where('model_type', User::class)
                    ->selectRaw('count(*)');
            }, 'users_count')
            ->get()
            ->toArray();

        $loggedInBrowserChartForCurrentMonth = Helper::getLoggedInBrowserCountForCurrentMonth();
        $loggedInLocationChartForCurrentMonth = Helper::getLoggedInLocationCountForCurrentMonth();
        $loggedInPlatformChartForCurrentMonth = Helper::getLoggedInPlatformCountForCurrentMonth();
        $loggedInClientDeviceChartForCurrentMonth = Helper::getLoggedInClientDeviceCountForCurrentMonth();

        $activeUsersCount = $tenantStatsService->getTotalActiveUsers();
        $userTrendData = $tenantStatsService->getNewUsersTrend($days);
        $tenantGrowthTrend = $tenantStatsService->getTenantGrowthTrend($days);
        $tenantStatusDistribution = $tenantStatsService->getTenantStatusDistribution();
        $topTenantsByUsers = $tenantStatsService->getTopTenantsByUsers();

        // Checklist Logic
        $tenant = app(\App\Services\Tenancy\TenantContext::class)->getTenant();
        $checklist = [
            'onboarding' => $tenant ? (bool) $tenant->onboarding_completed_at : true,
            'team' => User::count() > 1,
            'branding' => $tenant ? (! empty($tenant->logo) || (Helper::getTenantBranding('branding.primary_color') !== null && Helper::getTenantBranding('branding.primary_color') !== '#009EF7')) : true,
        ];

        return view('dashboard', [
            'users' => $users,
            'roleCount' => $roleCount,
            'loggedInBrowserChartForCurrentMonth' => $loggedInBrowserChartForCurrentMonth,
            'loggedInLocationChartForCurrentMonth' => $loggedInLocationChartForCurrentMonth,
            'loggedInPlatformChartForCurrentMonth' => $loggedInPlatformChartForCurrentMonth,
            'loggedInClientDeviceChartForCurrentMonth' => $loggedInClientDeviceChartForCurrentMonth,
            'activeUsersCount' => $activeUsersCount,
            'userTrendData' => $userTrendData,

            // New Data
            'tenantGrowthTrend' => $tenantGrowthTrend,
            'tenantStatusDistribution' => $tenantStatusDistribution,
            'topTenantsByUsers' => $topTenantsByUsers,
            'isolationModeDistribution' => $tenantStatsService->getIsolationModeDistribution(),

            // Billing Data
            'totalSuccessVolume' => \App\Models\Transaction::where('status', 'success')->sum('amount'), // Cents
            'totalTransactions' => \App\Models\Transaction::count(),
            'transactionTrend' => $tenantStatsService->getTransactionVolumeTrend($days),
            'activeSubscriptions' => \App\Models\Subscription::where('provider_status', 'active')->count(),

            'days' => $days,
            'checklist' => $checklist,
            'loginTrend' => $tenantStatsService->getLoginTrend($days),
            'usageGrowth' => $tenantStatsService->getUsageGrowth(),
        ]);
    }
}
