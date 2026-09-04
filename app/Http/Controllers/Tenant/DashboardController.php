<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function index(TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->getTenant();

        $checklist = [
            'onboarding' => (bool) $tenant->onboarding_completed_at,
            'team' => $tenant->users()->count() > 1,
            'branding' => (! empty($tenant->logo) || ! empty(data_get($tenant->meta, 'branding.primary_color')) || ! empty(data_get($tenant->meta, 'primary_color'))),
        ];

        return Inertia::render('Tenant/Dashboard', [
            'checklist' => $checklist,
            'links' => [
                'branding' => route('tenant.settings.index'),
                'team' => route('tenant.users.index'),
                'finishOnboarding' => route('tenant.onboarding.finish'),
            ],
        ]);
    }
}
