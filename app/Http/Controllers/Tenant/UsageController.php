<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\TenantFeatureUsage;
use App\Services\Tenancy\TenantContext;
use Inertia\Inertia;
use Inertia\Response;

final class UsageController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Show the tenant's plan limits, seat count and included features for the
     * current billing period.
     */
    public function index(string $subdomain): Response
    {
        $tenant = $this->tenantContext->getTenant();
        $package = $tenant->package()->with('features')->first();
        $periodStart = now()->startOfMonth();

        $usageBySlug = TenantFeatureUsage::query()
            ->where('tenant_id', $tenant->id)
            ->where(function ($query) use ($periodStart): void {
                $query->whereNull('period_start')
                    ->orWhere('period_start', '>=', $periodStart);
            })
            ->pluck('used_count', 'feature_slug');

        $features = $package?->features ?? collect();

        $limits = $features
            ->filter(fn (Feature $feature): bool => $feature->type === 'limit' && (int) data_get($feature, 'pivot.value') > 0)
            ->map(function (Feature $feature) use ($usageBySlug): array {
                $limit = (int) data_get($feature, 'pivot.value');
                $used = (int) ($usageBySlug[$feature->slug] ?? 0);

                return [
                    'slug' => $feature->slug,
                    'name' => $feature->name,
                    'used' => $used,
                    'limit' => $limit,
                ];
            })
            ->values();

        $seatFeature = $features->firstWhere('slug', 'team_seats');

        return Inertia::render('Tenant/Settings/Usage', [
            'planName' => $package?->name ?? 'Free',
            'periodLabel' => $periodStart->format('F Y'),
            'limits' => $limits,
            'seats' => $seatFeature ? [
                'used' => $tenant->users()->count(),
                'limit' => (int) data_get($seatFeature, 'pivot.value'),
            ] : null,
            'includedFeatures' => $features
                ->filter(fn (Feature $feature): bool => $feature->type === 'boolean'
                    && in_array(data_get($feature, 'pivot.value'), ['true', '1', 1, true], true))
                ->map(fn (Feature $feature): array => [
                    'slug' => $feature->slug,
                    'name' => $feature->name,
                    'description' => $feature->description,
                ])
                ->values(),
        ]);
    }
}
