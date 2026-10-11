<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Payment\StripeConnect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stripe sends the tenant back to one fixed address on the central domain, so
 * the sealed state, not a session, says which tenant is connecting. The
 * tenant is then sent back to their own settings page.
 */
final class StripeConnectCallbackController extends Controller
{
    public function __invoke(Request $request, StripeConnect $stripeConnect): RedirectResponse
    {
        $tenant = $stripeConnect->tenantFromState((string) $request->query('state'));

        if ($tenant === null) {
            return redirect()->route('home')->with('error', 'That Stripe connection link has expired. Start again from your payment settings.');
        }

        $settings = $tenant->url('/settings/payments');

        if ($request->query('error') || ! $request->filled('code')) {
            return redirect()->away("{$settings}?stripe=declined");
        }

        try {
            $stripeConnect->connect($tenant, (string) $request->query('code'));
        } catch (Throwable $e) {
            Log::error('Stripe Connect authorization failed', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);

            return redirect()->away("{$settings}?stripe=failed");
        }

        return redirect()->away("{$settings}?stripe=connected");
    }
}
