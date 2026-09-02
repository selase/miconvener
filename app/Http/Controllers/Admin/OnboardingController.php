<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class OnboardingController extends Controller
{
    public function index(Request $request, ?string $subdomain = null): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
    {
        $tenant = app(\App\Services\Tenancy\TenantContext::class)->getTenant();

        return view('admin.onboarding.wizard', [
            'tenant' => $tenant,
        ]);
    }

    public function updateBranding(Request $request, ?string $subdomain = null): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'primary_color' => ['nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'logo' => ['nullable', 'image', 'max:2048'], // Max 2MB
        ]);

        $tenant = app(\App\Services\Tenancy\TenantContext::class)->getTenant();

        $meta = $tenant->meta ?? [];
        $meta['branding']['primary_color'] = $request->primary_color;

        $updateData = [
            'name' => $request->name,
            'meta' => $meta,
        ];

        if ($request->hasFile('logo')) {
            $path = \App\Libraries\Helper::processUploadedFile(
                $request,
                'logo',
                'tenant_logo',
                'logos/tenants',
                config('app.env') === 'production' ? 's3' : 'public'
            );
            $updateData['logo'] = $path;
        }

        $tenant->update($updateData);

        $dashboardRoute = request()->route('subdomain') ? 'tenant.dashboard' : 'dashboard';

        return redirect()->route($dashboardRoute)->with('success', 'Branding updated!')->with('onboarding_just_completed', true);
    }

    public function finish(Request $request, ?string $subdomain = null): RedirectResponse
    {
        $tenant = app(\App\Services\Tenancy\TenantContext::class)->getTenant();

        if ($tenant) {
            $tenant->update(['onboarding_completed_at' => now()]);
        }

        return redirect()->route('dashboard')->with('success', 'Onboarding completed!')->with('onboarding_just_completed', true);
    }
}
