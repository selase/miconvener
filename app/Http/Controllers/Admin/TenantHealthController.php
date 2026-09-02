<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\View\View;

final class TenantHealthController extends Controller
{
    public function index(): View
    {
        $this->authorize('access-superadmin-dashboard');

        $tenants = Tenant::with('package')->get()->map(function ($tenant): array {
            $hasCustomDomain = ! empty($tenant->custom_domain);
            $isResolvable = $hasCustomDomain ? checkdnsrr($tenant->custom_domain, 'ANY') : null;

            return [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'package' => $tenant->package->name ?? 'N/A',
                'custom_domain' => $tenant->custom_domain,
                'domain_status' => $tenant->custom_domain_status,
                'is_resolvable' => $isResolvable,
                'db_status' => 'Healthy', // Placeholder for actual DB connectivity check
                'storage_status' => 'Healthy', // Placeholder
            ];
        });

        return view('admin.health.tenants', [
            'tenants' => $tenants,
            'breadcrumbs' => [
                ['link' => route('dashboard'), 'name' => __('Home')],
                ['name' => __('Tenant Health')],
            ],
        ]);
    }
}
