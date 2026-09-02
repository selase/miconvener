<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;

final class DashboardController extends Controller
{
    public function index(): Factory|View
    {
        $tenant = app(\App\Services\Tenancy\TenantContext::class)->getTenant();
        $meteringService = app(\App\Services\Tenancy\FeatureMeteringService::class);

        // Fetch usage for enabled metered features
        $usages = $tenant->features()
            ->where('enabled', true)
            ->get()
            ->filter(fn ($feature): bool => ($feature->meta['type'] ?? null) === 'limit')
            ->map(fn ($feature) => $meteringService->getUsage($tenant, $feature->feature_key))
            ->values()
            ->all();

        // Checklist Logic
        $checklist = [
            'onboarding' => (bool) $tenant->onboarding_completed_at,
            'team' => $tenant->users()->count() > 1,
            'branding' => (! empty($tenant->logo) || ! empty(data_get($tenant->meta, 'branding.primary_color')) || ! empty(data_get($tenant->meta, 'primary_color'))),
        ];

        return view('tenant.dashboard', ['usages' => $usages, 'checklist' => $checklist]);
    }
}
