<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrganisationPayoutPolicy;
use App\Models\Tenant;
use App\Services\Finance\PayoutPolicies;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class PayoutPolicyController extends Controller
{
    public function index(Request $request, PayoutPolicies $policies): View
    {
        $policies->authorisePlatform($request->user());
        $page = OrganisationPayoutPolicy::query()->latest()->paginate(30);

        return view('admin.billing.payouts.policies', ['policies' => $page,
            'tenants' => Tenant::query()->whereIn('id', $page->pluck('tenant_id'))->pluck('name', 'id')]);
    }

    public function audit(\App\Http\Requests\Admin\ReviewPayoutReconciliationRequest $request, \App\Services\Finance\PayoutFinancialAudit $audit): RedirectResponse
    {
        app(PayoutPolicies::class)->authorisePlatform($request->user());
        $report = $audit->inspect((int) $request->validated('external_cash'));
        if ($request->validated('action') === 'record') {
            $audit->record($report, $request->user(), (string) $request->validated('statement'), (string) $request->validated('note'));

            return back()->with('success', 'Matched provider and ledger evidence certified. No transfer sent.');
        }

        if (! $report['matched']) {
            $audit->recordException($report, $request->user());
        }

        return back()->with($report['matched'] ? 'success' : 'error', $report['matched']
            ? 'Read-only check matched. Review the statement before certifying; no checkpoint was recorded.'
            : 'Reconciliation needs review: '.count($report['unresolved']).' unresolved transfer(s), provider '.$report['provider_balance'].' pesewas, ledger '.$report['book_cash'].' pesewas. New submissions are blocked until a fresh matched reconciliation is certified.');
    }

    public function pause(Request $request, string $policy, PayoutPolicies $policies): RedirectResponse
    {
        $policies->pausePlatform(OrganisationPayoutPolicy::query()->findOrFail($policy), $request->user());

        return back()->with('success', 'Policy paused. Submitted transfers continue through reconciliation.');
    }

    public function approve(Request $request, string $policy, PayoutPolicies $policies): RedirectResponse
    {
        $policies->approve(OrganisationPayoutPolicy::query()->findOrFail($policy), $request->user());

        return back()->with('success', 'Policy approved. Its 24-hour cooling period has started. No payout was sent.');
    }
}
