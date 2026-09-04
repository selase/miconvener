<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Libraries\Helper;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class OnboardingController extends Controller
{
    public function index(string $subdomain): Response
    {
        $tenant = app(TenantContext::class)->getTenant();

        return Inertia::render('Tenant/Onboarding/Wizard', [
            'org' => [
                'name' => $tenant->name,
                'primary_color' => data_get($tenant->meta, 'branding.primary_color', '#009EF7'),
                'logo' => Helper::getTenantLogoUrl(),
            ],
        ]);
    }

    public function updateBranding(Request $request, string $subdomain): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'primary_color' => ['nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'logo' => ['nullable', 'image', 'max:2048'],
        ]);

        $tenant = app(TenantContext::class)->getTenant();

        $meta = $tenant->meta ?? [];
        $meta['branding']['primary_color'] = $request->primary_color;

        $updateData = [
            'name' => $request->name,
            'meta' => $meta,
        ];

        if ($request->hasFile('logo')) {
            $updateData['logo'] = Helper::processUploadedFile(
                $request,
                'logo',
                'tenant_logo',
                'logos/tenants',
                config('app.env') === 'production' ? 's3' : 'public'
            );
        }

        $tenant->update($updateData);

        return redirect()->route('tenant.dashboard', ['subdomain' => $subdomain])
            ->with('success', 'Branding updated!');
    }

    public function finish(string $subdomain): RedirectResponse
    {
        $tenant = app(TenantContext::class)->getTenant();

        if ($tenant) {
            $tenant->update(['onboarding_completed_at' => now()]);
        }

        return redirect()->route('tenant.dashboard', ['subdomain' => $subdomain])
            ->with('success', 'Onboarding completed!');
    }
}
