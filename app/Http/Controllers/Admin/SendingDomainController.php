<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreSendingDomainRequest;
use App\Models\Tenant;
use App\Services\Mail\SendingDomainService;
use Aws\Exception\AwsException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Superadmin set-up of an organiser's own sending domain (Enterprise only).
 * The admin registers the domain, sends the organiser the DKIM records, and
 * the scheduled check switches it on once SES finds them.
 */
final class SendingDomainController extends Controller
{
    public function __construct(private readonly SendingDomainService $domains) {}

    public function show(Tenant $tenant): View
    {
        $this->authorize('access-superadmin-dashboard');

        return view('admin.tenants.sending-domain', [
            'tenant' => $tenant,
            'sendingDomain' => $tenant->sendingDomain,
            'canHaveSendingDomain' => $tenant->canHaveSendingDomain(),
        ]);
    }

    public function store(StoreSendingDomainRequest $request, Tenant $tenant): RedirectResponse
    {
        if (! $tenant->canHaveSendingDomain()) {
            return back()->with('error', 'Sending from their own domain is an Enterprise feature. Move the organisation to Enterprise first.');
        }

        $existing = $tenant->sendingDomain;

        try {
            if ($existing !== null && $existing->domain !== $request->validated('domain')) {
                $this->domains->remove($existing);
            }

            $this->domains->start($tenant, $request->validated('domain'), $request->validated('from_address'));
        } catch (AwsException $e) {
            report($e);

            return back()->withInput()->with('error', 'Amazon SES refused the domain: '.($e->getAwsErrorMessage() ?? $e->getMessage()));
        }

        return redirect()->route('tenants.sending-domain.show', $tenant)
            ->with('success', 'Domain registered. Send the organiser the three DNS records below.');
    }

    public function check(Tenant $tenant): RedirectResponse
    {
        $this->authorize('access-superadmin-dashboard');

        $sendingDomain = $tenant->sendingDomain;

        if ($sendingDomain === null) {
            return back();
        }

        try {
            $sendingDomain = $this->domains->refresh($sendingDomain);
        } catch (AwsException $e) {
            report($e);

            return back()->with('error', 'Could not reach Amazon SES: '.($e->getAwsErrorMessage() ?? $e->getMessage()));
        }

        return back()->with('success', $sendingDomain->isVerified()
            ? "Verified. Event mail now comes from {$sendingDomain->from_address}."
            : 'Not verified yet. DNS changes can take up to 72 hours; this is also checked every 15 minutes.');
    }

    public function destroy(Tenant $tenant): RedirectResponse
    {
        $this->authorize('access-superadmin-dashboard');

        $sendingDomain = $tenant->sendingDomain;

        if ($sendingDomain !== null) {
            try {
                $this->domains->remove($sendingDomain);
            } catch (AwsException $e) {
                report($e);

                return back()->with('error', 'Could not reach Amazon SES: '.($e->getAwsErrorMessage() ?? $e->getMessage()));
            }
        }

        return back()->with('success', 'Removed. Event mail comes from the MiConvener address again.');
    }
}
