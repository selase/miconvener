<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class TenantStatsService
{
    public function getTotalActiveUsers(): int
    {
        return \Illuminate\Support\Facades\Cache::remember('tenant_stats.active_users', 300, fn() => User::query()->where('status', User::STATUS_ACTIVE)->count());
    }

    public function getNewUsersTrend(int $days): array
    {
        $startDate = Carbon::now()->subDays($days - 1)->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        $dateExpr = $this->getDateExpression('created_at');

        $data = User::query()
            ->selectRaw("{$dateExpr} as date, COUNT(*) as count")
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy(DB::raw($dateExpr))
            ->orderBy('date')
            ->get()
            ->pluck('count', 'date');

        $labels = [];
        $counts = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $startDate->copy()->addDays($i)->format('Y-m-d');
            $labels[] = $date;
            $counts[] = $data->get($date, 0);
        }

        return ['labels' => $labels, 'data' => $counts];
    }

    public function getTenantGrowthTrend(int $days = 7): array
    {
        $startDate = Carbon::now()->subDays($days - 1)->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        $dateExpr = $this->getDateExpression('created_at');

        $data = \App\Models\Tenant::query()
            ->selectRaw("{$dateExpr} as date, COUNT(*) as count")
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy(DB::raw($dateExpr))
            ->orderBy('date')
            ->get()
            ->pluck('count', 'date');

        $labels = [];
        $counts = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $startDate->copy()->addDays($i)->format('Y-m-d');
            // Assuming we want Y-m-d or m-d for labels. Let's match new users trend (Y-m-d)
            // But test expected m-d. Let's fix test or code.
            // Let's stick to Y-m-d for consistency, or m-d for cleaner chart.
            // Test expects m-d.
            $labels[] = $startDate->copy()->addDays($i)->format('m-d');
            $counts[] = $data->get($date, 0);
        }

        return ['labels' => $labels, 'data' => $counts];
    }

    public function getTenantStatusDistribution(): array
    {
        $data = \App\Models\Tenant::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status'); // status is enum value in DB usually

        $labels = [];
        $counts = [];

        foreach ($data as $status => $count) {
            // If cast to Enum, status might be 'active' string or int depending on storage.
            // The model casts 'status' => TenantStatusEnum.
            // pluck might return the raw value or the Enum object if casting happens?
            // Usually DB query raw returns raw value.
            // Let's assume raw string/int.
            $labels[] = $status instanceof \App\Enum\TenantStatusEnum ? $status->value : mb_strtoupper((string) $status);
            $counts[] = $count;
        }

        // Ensure consistent order for tests? Or map enums?
        // Simple implementation first.
        return ['labels' => $labels, 'data' => $counts];
    }

    public function getTopTenantsByUsers(int $limit = 5): array
    {
        // Requires sorting by related count.
        $topTenants = \App\Models\Tenant::query()
            ->withCount('users')
            ->orderByDesc('users_count')
            ->limit($limit)
            ->get();

        return [
            'labels' => $topTenants->pluck('name')->toArray(),
            'data' => $topTenants->pluck('users_count')->toArray(),
        ];
    }

    public function getIsolationModeDistribution(): array
    {
        $data = \App\Models\Tenant::query()
            ->selectRaw('isolation_mode, COUNT(*) as count')
            ->groupBy('isolation_mode')
            ->pluck('count', 'isolation_mode');

        return [
            'labels' => $data->keys()->map(fn ($k): string => ucfirst((string) $k))->toArray(),
            'data' => $data->values()->toArray(),
        ];
    }

    public function getLoginTrend(int $days = 7): array
    {
        $startDate = Carbon::now()->subDays($days - 1)->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        $dateExpr = $this->getDateExpression('login_at');

        $data = \App\Models\UserLoginHistory::query()
            ->selectRaw("{$dateExpr} as date, COUNT(*) as count")
            ->whereBetween('login_at', [$startDate, $endDate])
            ->groupBy(DB::raw($dateExpr))
            ->orderBy('date')
            ->get()
            ->pluck('count', 'date');

        $labels = [];
        $counts = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $startDate->copy()->addDays($i)->format('Y-m-d');
            $labels[] = $startDate->copy()->addDays($i)->format('M d');
            $counts[] = $data->get($date, 0);
        }

        return ['labels' => $labels, 'data' => $counts];
    }

    public function getUsageGrowth(): array
    {
        // Sample data for demonstration
        return [
            'labels' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            'data' => [45, 52, 38, 65, 48, 23, 15],
        ];
    }

    public function getTransactionVolumeTrend(int $days = 7): array
    {
        $startDate = Carbon::now()->subDays($days - 1)->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        $dateExpr = $this->getDateExpression('created_at');

        $data = \App\Models\Transaction::query()
            ->selectRaw("{$dateExpr} as date, SUM(amount) as total_amount")
            ->where('status', 'success')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy(DB::raw($dateExpr))
            ->orderBy('date')
            ->get()
            ->pluck('total_amount', 'date');

        $labels = [];
        $counts = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $startDate->copy()->addDays($i)->format('Y-m-d');
            $labels[] = $startDate->copy()->addDays($i)->format('M d');
            // Store as decimal (dollars)
            $counts[] = $data->get($date, 0) / 100;
        }

        return ['labels' => $labels, 'data' => $counts];
    }

    private function getDateExpression(string $column): string
    {
        $driver = DB::connection()->getDriverName();

        return $driver === 'pgsql' ? "CAST($column AS DATE)" : "DATE($column)";
    }
}
