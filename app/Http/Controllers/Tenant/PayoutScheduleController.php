<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Finance\ApproveScheduledPayoutRequest;
use App\Http\Requests\Tenant\Finance\StorePayoutScheduleRequest;
use App\Models\EventPayout;
use App\Models\OrganisationPayoutPolicy;
use App\Models\ScheduledPayoutProposal;
use App\Models\Tenant;
use App\Models\TenantPayoutAccount;
use App\Services\Finance\PayoutPolicies;
use App\Services\Finance\ScheduledPayoutApproval;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PayoutScheduleController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('read finance');
        $tenant = $this->tenant();
        abort_unless($tenant->users()->whereKey($request->user()->id)->exists(), 403);
        $policies = OrganisationPayoutPolicy::query()->where('tenant_id', $tenant->id)->latest()->limit(50)->get();
        $accounts = TenantPayoutAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('is_verified', true)->get();
        $page = ScheduledPayoutProposal::query()->where('tenant_id', $tenant->id)->latest()->paginate(20);
        $payouts = EventPayout::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereIn('id', $page->getCollection()->pluck('payout_id')->filter())->get()->keyBy('id');

        $eventNames = \App\Models\Event::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereIn('id', $page->getCollection()->pluck('event_id')->filter())->pluck('name', 'id');

        return Inertia::render('Tenant/Finance/Schedules', ['policies' => $policies->map(fn ($p): array => [
            'id' => $p->id, 'category' => $p->category, 'version' => $p->version, 'status' => $p->status, 'cadence' => $p->cadence,
            'weekday' => $p->weekday, 'local_time' => $p->local_time, 'timezone' => $p->timezone, 'active_after' => $p->active_after?->toIso8601String(),
            'minimum_amount' => $p->minimum_amount, 'maximum_amount' => $p->maximum_amount, 'daily_limit' => $p->daily_limit,
            'rolling_limit' => $p->rolling_limit, 'reserve_amount' => $p->reserve_amount,
            'destination' => $p->destination_display,
        ]), 'proposals' => $page->getCollection()->map(function ($p) use ($payouts, $eventNames): array {
            $payout = $payouts->get($p->payout_id);

            return ['id' => $p->id, 'policy_id' => $p->policy_id, 'scope' => $p->scope_key, 'scope_label' => $eventNames->get($p->event_id) ?? $p->scope_key, 'amount' => $p->amount,
                'snapshot' => $p->snapshot, 'status' => $p->status, 'reason' => $p->reason, 'expires_at' => $p->expires_at->toIso8601String(),
                'submit_before' => $p->submit_before?->toIso8601String(), 'payout_status' => $payout?->status, 'failure_reason' => $payout?->failure_reason];
        }), 'pagination' => ['previous' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
            'accounts' => $accounts->map(fn ($a): array => ['id' => $a->id, 'label' => $a->label.' · '.$a->maskedAccountNumber()]),
            'canManage' => (bool) $request->user()->can('manage payout schedules'),
            'canPay' => ['commerce' => (bool) $request->user()->can('manage store finance'), 'other' => (bool) $request->user()->can('manage payouts')],
            'securityReady' => $request->user()->two_factor_confirmed_at !== null && filled($request->user()->two_factor_secret),
            'platformReady' => config('services.payout_schedules.liquidity_controls') === true && config('services.payout_schedules.paused') !== true]);
    }

    public function store(StorePayoutScheduleRequest $request, PayoutPolicies $policies): JsonResponse
    {
        $policy = $policies->request($this->tenant(), $request->user(), $request->validated());

        return response()->json(['id' => $policy->id, 'status' => $policy->status], 201);
    }

    public function pause(Request $request, string $subdomain, string $policy, PayoutPolicies $policies): JsonResponse
    {
        $policies->pause(OrganisationPayoutPolicy::query()->where('tenant_id', $this->tenant()->id)->findOrFail($policy), $request->user());

        return response()->json(['message' => 'Schedule paused. Submitted transfers will still be reconciled.']);
    }

    public function action(ApproveScheduledPayoutRequest $request, string $subdomain, string $proposal, ScheduledPayoutApproval $approval): JsonResponse
    {
        $current = ScheduledPayoutProposal::query()->where('tenant_id', $this->tenant()->id)->findOrFail($proposal);
        if ($request->routeIs('tenant.finance.schedules.refresh')) {
            app(\App\Services\Finance\ScheduledPayoutPlanner::class)->refreshProposal($current, $request->user());

            return response()->json(['message' => 'Fresh proposal prepared. Review it before approval; its original expiry is unchanged.']);
        }
        if ($request->routeIs('tenant.finance.schedules.approve')) {
            $payout = $approval->reserve($current, $request->user(), $request->validated('operation_key'));

            return response()->json(['message' => 'Payout reserved. Review and submit within 30 minutes.', 'payout_id' => $payout->id]);
        }
        if ($request->routeIs('tenant.finance.schedules.cancel')) {
            $approval->cancel($current, $request->user());

            return response()->json(['message' => 'Unsent payout cancelled. No transfer submitted.']);
        }
        $result = $request->routeIs('tenant.finance.schedules.finalize')
            ? $approval->finalize($current, $request->user(), $request->validated('otp'))
            : $approval->submit($current, $request->user());

        return response()->json(\Illuminate\Support\Arr::except($result, ['http_status', 'transfer_code']), $result['http_status'] ?? 200);
    }

    private function tenant(): Tenant
    {
        return app(TenantContext::class)->getTenant() ?? abort(403);
    }
}
