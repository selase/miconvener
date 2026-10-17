<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Giving\ChangeFundStateRequest;
use App\Http\Requests\Tenant\Giving\SaveFundRequest;
use App\Models\GivingFund;
use App\Models\Tenant;
use App\Services\Giving\GivingFunds;
use App\Services\Giving\GivingRollout;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

final class GivingFundController extends Controller
{
    public function index(\Illuminate\Http\Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $tenant = app(TenantContext::class)->getTenant();
        abort_unless(app(GivingRollout::class)->enabled($tenant) || $tenant->hasGivingMoney(), 404);
        abort_unless($request->user()->can('manage giving') || $request->user()->can('read finance'), 403);
        $finance = $request->user()->can('read finance');
        $today = \Carbon\CarbonImmutable::now(\App\Models\CommunitySettings::for($tenant)->timezone);
        $funds = GivingFund::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('name')->get();
        $response = \Inertia\Inertia::render('Tenant/Community/MoneyGiving', [
            'funds' => $funds->map(fn (GivingFund $fund) => [...$fund->only(['id', 'name', 'slug', 'description', 'button_label', 'currency', 'kind', 'target', 'minimum_amount', 'ends_on', 'close_on_target', 'state', 'visibility', 'progress', 'event_id']),
                'totals' => $finance ? app(\App\Services\Giving\GivingProgress::class)->for($fund) : null,
                'url' => route('public.giving.show', ['subdomain' => $tenant->slug, 'slug' => $fund->slug])]),
            'delivery' => $finance ? [
                'reminders' => \App\Models\GivingReminder::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get(['delivery_state'])->countBy('delivery_state'),
                'receipts' => \App\Models\GivingReceiptNotice::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get(['state'])->countBy('state'),
            ] : null,
            'canManageGiving' => $request->user()->can('manage giving'), 'canReadFinance' => $finance,
            'enabled' => app(GivingRollout::class)->enabled($tenant),
            'canManageDues' => $request->user()->can('manage members'),
            'canRefund' => $request->user()->can('process refunds'),
            'report' => $finance ? app(\App\Services\Giving\GivingReport::class)->summary($tenant, $today->startOfMonth()->utc(), $today->endOfDay()->utc()) : null,
            'adjustments' => $finance ? \App\Models\GivingAdjustment::withoutGlobalScopes()->with('fund:id,name,currency')->where('tenant_id', $tenant->id)->latest()->limit(50)->get()->map(fn ($row) => [...$row->only(['id', 'principal', 'kind', 'status', 'commission_reversal', 'provider_refund_reference']), 'fund_name' => $row->fund->name, 'currency' => $row->fund->currency]) : [],
            'gifts' => $finance ? \App\Models\GivingGift::withoutGlobalScopes()->with('fund:id,name')->where('tenant_id', $tenant->id)->latest()->limit(50)->get()->map(fn ($gift) => [
                ...$gift->only(['id', 'fund_id', 'amount', 'currency', 'source', 'status', 'is_anonymous']),
                'fund_name' => $gift->fund->name,
                'payer_name' => $gift->is_anonymous ? null : $gift->payer_name,
            ]) : [],
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function store(SaveFundRequest $request, GivingFunds $funds): JsonResponse
    {
        return response()->json(['fund' => $funds->save($this->tenant(), $request->user(), $request->validated())], 201);
    }

    public function update(SaveFundRequest $request, string $subdomain, string $fund, GivingFunds $funds): JsonResponse
    {
        return response()->json(['fund' => $funds->save($this->tenant(), $request->user(), $request->validated(), $this->find($fund))]);
    }

    public function close(ChangeFundStateRequest $request, string $subdomain, string $fund, GivingFunds $funds): JsonResponse
    {
        $model = $this->find($fund);
        $funds->close($model, $request->user(), $request->validated('reason'));

        return response()->json(['fund' => $model]);
    }

    public function reopen(ChangeFundStateRequest $request, string $subdomain, string $fund, GivingFunds $funds): JsonResponse
    {
        $model = $this->find($fund);
        $funds->reopen($model, $request->user(), $request->validated('reason'));

        return response()->json(['fund' => $model]);
    }

    public function archive(ChangeFundStateRequest $request, string $subdomain, string $fund, GivingFunds $funds): JsonResponse
    {
        $model = $this->find($fund);
        $funds->archive($model, $request->user(), $request->validated('reason'));

        return response()->json(['fund' => $model]);
    }

    private function find(string $id): GivingFund
    {
        $tenant = $this->tenant();

        return GivingFund::withoutGlobalScopes()->where('tenant_id', $tenant->id)->findOrFail($id);
    }

    private function tenant(): Tenant
    {
        $tenant = app(TenantContext::class)->getTenant();
        abort_unless($tenant instanceof Tenant && app(GivingRollout::class)->enabled($tenant), 404);

        return $tenant;
    }
}
