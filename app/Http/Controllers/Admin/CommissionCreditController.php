<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantCommissionCredit;
use App\Services\Finance\CommissionCredit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Commission the platform owes organizers back after refunds on their own
 * Paystack account. The credit is netted against their later sales; what is
 * left, for instance after a cancelled event, is paid out by transfer and then
 * recorded here. Open to the superadmin and to platform finance staff through
 * the "manage commission credits" permission.
 */
final class CommissionCreditController extends Controller
{
    public function index(): View
    {
        $this->authorize('manage commission credits');

        $credits = TenantCommissionCredit::query()
            ->withoutGlobalScopes()
            ->where('amount', '>', 0)
            ->orderByDesc('amount')
            ->get();

        $tenants = Tenant::query()->whereIn('id', $credits->pluck('tenant_id'))->get()->keyBy('id');

        return view('admin.billing.commission-credits.index', [
            'credits' => $credits->filter(fn (TenantCommissionCredit $credit): bool => $tenants->has($credit->tenant_id))->values(),
            'tenants' => $tenants,
        ]);
    }

    public function settle(Request $request, Tenant $tenant, CommissionCredit $commissionCredit): RedirectResponse
    {
        $this->authorize('manage commission credits');

        $validated = $request->validate([
            'currency' => ['required', 'string', 'size:3'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ], [
            'amount.gt' => 'Enter the amount you paid out, above zero.',
        ]);

        $currency = mb_strtoupper($validated['currency']);
        $owed = $commissionCredit->balance($tenant, $currency);

        if ($owed < 1) {
            return back()->with('error', "{$tenant->name} has no {$currency} credit to settle.");
        }

        $requested = (int) round((float) $validated['amount'] * 100);

        if ($requested > $owed) {
            return back()->with('error', sprintf('%s is owed %s %s, which is less than the %s you entered.', $tenant->name, $currency, number_format($owed / 100, 2), number_format($requested / 100, 2)));
        }

        $recorded = $commissionCredit->settle($tenant, $currency, $requested);

        activity()
            ->causedBy($request->user())
            ->performedOn($tenant)
            ->withProperties(['currency' => $currency, 'amount' => $recorded])
            ->log('Recorded a commission credit payout');

        return back()->with('success', sprintf('Recorded %s %s paid to %s. %s %s remains.', $currency, number_format($recorded / 100, 2), $tenant->name, $currency, number_format($commissionCredit->balance($tenant, $currency) / 100, 2)));
    }
}
