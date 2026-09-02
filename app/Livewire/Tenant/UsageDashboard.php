<?php

declare(strict_types=1);

namespace App\Livewire\Tenant;

use App\Models\TenantFeatureUsage;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class UsageDashboard extends Component
{
    public function render(): View
    {
        $tenant = app(TenantContext::class)->getTenant();
        $package = $tenant?->load('package.features')->package;

        $periodStart = now()->startOfMonth();

        // Current-period usage keyed by feature_slug
        $usageBySlug = TenantFeatureUsage::where('tenant_id', $tenant->id)
            ->where(function ($q) use ($periodStart): void {
                $q->whereNull('period_start')
                    ->orWhere('period_start', '>=', $periodStart);
            })
            ->get()
            ->keyBy('feature_slug');

        // Build rows for limit-type features that have a numeric value on the package
        $usageRows = collect();

        if ($package) {
            foreach ($package->features as $feature) {
                $limit = (int) ($feature->pivot->value ?? 0);
                // Only show if it's a numeric limit (value > 0 and not 'true'/'false')
                if ($feature->type !== 'limit') {
                    continue;
                }
                if ($limit <= 0) {
                    continue;
                }

                $used = (int) ($usageBySlug->get($feature->slug)?->used_count ?? 0);
                $pct = min((int) round(($used / $limit) * 100), 100);
                $color = match (true) {
                    $pct >= 90 => 'danger',
                    $pct >= 70 => 'warning',
                    default => 'primary',
                };

                $usageRows->push([
                    'slug' => $feature->slug,
                    'name' => $feature->name,
                    'used' => $used,
                    'limit' => $limit,
                    'pct' => $pct,
                    'color' => $color,
                ]);
            }
        }

        // Boolean features enabled on this tenant's package
        $enabledFeatures = $package
            ? $package->features->filter(fn ($f): bool => $f->type === 'boolean' && in_array($f->pivot->value, ['true', '1', 1, true], true))
            : collect();

        // Team member count
        $teamCount = $tenant?->users()->count() ?? 0;
        $seatLimit = null;
        if ($package) {
            $seatFeature = $package->features->firstWhere('slug', 'team_seats');
            if ($seatFeature) {
                $seatLimit = (int) ($seatFeature->pivot->value ?? 0);
            }
        }

        return view('livewire.tenant.usage-dashboard', [
            'tenant' => $tenant,
            'package' => $package,
            'usageRows' => $usageRows,
            'enabledFeatures' => $enabledFeatures,
            'periodStart' => $periodStart,
            'teamCount' => $teamCount,
            'seatLimit' => $seatLimit,
        ]);
    }
}
