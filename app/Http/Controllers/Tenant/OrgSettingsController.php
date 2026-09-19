<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateOrgSettingsRequest;
use App\Libraries\Helper;
use App\Services\Tenancy\FeatureService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class OrgSettingsController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Display the tenant settings page.
     */
    public function index(): Response
    {
        $this->authorize('manage organization settings');
        $tenant = $this->tenantContext->getTenant();

        return Inertia::render('Tenant/Settings/Index', [
            'org' => [
                'name' => $tenant->name,
                'email' => $tenant->email,
                'phone_number' => $tenant->phone_number,
                'logo' => Helper::getTenantLogoUrl(),
                'can_use_own_logo' => $tenant->canUseOwnLogo(),
                'can_use_custom_domain' => $tenant->featureEnabled(FeatureService::FEATURE_CUSTOM_DOMAINS),
                'primary_color' => data_get($tenant->meta, 'primary_color', '#009EF7'),
                'require_2fa' => (bool) $tenant->require_2fa,
                'custom_domain' => $tenant->custom_domain,
                'custom_domain_status' => $tenant->custom_domain_status,
            ],
        ]);
    }

    /**
     * Update the tenant settings.
     */
    public function update(UpdateOrgSettingsRequest $request): RedirectResponse
    {
        $this->authorize('manage organization settings');
        $tenant = $this->tenantContext->getTenant();

        $validatedData = $request->validated();

        $tenant->name = $validatedData['name'];
        $tenant->email = $validatedData['email'];
        $tenant->phone_number = $validatedData['phone_number'];
        $tenant->require_2fa = $request->boolean('require_2fa');

        if (array_key_exists('custom_domain', $validatedData)) {
            $submittedDomain = $validatedData['custom_domain'];

            if ($tenant->custom_domain !== $submittedDomain) {
                $tenant->custom_domain = $submittedDomain;
                $tenant->custom_domain_status = 'pending';
                $tenant->custom_domain_verified_at = null;
            }
        }

        if ($request->hasFile('logo')) {
            if (! $tenant->canUseOwnLogo()) {
                return back()->withErrors([
                    'logo' => 'Your own logo is an Enterprise feature. Talk to us about upgrading to use it.',
                ]);
            }

            $disk = config('app.env') === 'production' ? 's3' : 'public';
            if ($tenant->logo) {
                Helper::deleteFile($tenant->logo, $disk);
            }
            $tenant->logo = Helper::processUploadedFile($request, 'logo', 'logo', 'tenant/logo', $disk);
        }

        $meta = $tenant->meta ?? [];
        $meta['primary_color'] = $validatedData['primary_color'] ?? $meta['primary_color'] ?? '#009EF7';
        $tenant->meta = $meta;

        $tenant->save();

        return back()->with([
            'status' => 'success',
            'message' => __('Organization settings updated successfully.'),
        ]);
    }

    public function verifyDomain(): RedirectResponse
    {
        $this->authorize('manage organization settings');
        /** @var \App\Models\Tenant $tenant */
        $tenant = $this->tenantContext->getTenant();

        abort_unless($tenant->featureEnabled(FeatureService::FEATURE_CUSTOM_DOMAINS), 403);

        if (! $tenant->custom_domain) {
            return back()->withErrors(['custom_domain' => 'No custom domain configured.']);
        }

        $cname = config('app.url');
        $host = parse_url((string) $cname, PHP_URL_HOST);

        // Simple CNAME/A record check
        // In production, use a more robust DNS resolver
        $records = dns_get_record($tenant->custom_domain, DNS_CNAME + DNS_A);
        $verified = false;

        foreach ($records as $record) {
            if (isset($record['target']) && $record['target'] === $host) {
                $verified = true;
                break;
            }
            // Also check A record if they point to IPs
            if (isset($record['ip']) && $record['ip'] === '127.0.0.1') { // Replace with actual IPs
                $verified = true;
                break;
            }
        }

        // For local dev/testing, we might want to bypass or mock
        if (config('app.env') === 'local') {
            $verified = true; // Auto-verify in local for testing flows
        }

        if ($verified) {
            $tenant->update([
                'custom_domain_status' => 'active',
                'custom_domain_verified_at' => now(),
            ]);

            return back()->with([
                'status' => 'success',
                'message' => 'Domain verified successfully. active!',
            ]);
        }

        return back()->with([
            'status' => 'error',
            'message' => 'Could not verify DNS records. Please ensure your CNAME points to '.$host,
        ]);
    }
}
