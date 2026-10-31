<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enum\LedgerCategoryEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Dues\StoreDuesPayoutRequest;
use App\Models\EventPayout;
use App\Models\LedgerAccount;
use App\Models\Tenant;
use App\Models\TenantPayoutAccount;
use App\Services\Dues\DuesBalance;
use App\Services\Finance\CommunityPayouts;
use App\Services\Finance\PayoutRelease;
use App\Services\Finance\PayoutSender;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Paying collected member dues out to the organisation. Dues payouts are
 * EventPayout rows in the dues category with no event; sending and releasing
 * them goes through the same PayoutSender and PayoutRelease as event payouts.
 */
final class DuesPayoutController extends Controller
{
    public function index(DuesBalance $duesBalance): Response
    {
        $this->authorize('read finance');
        $tenant = $this->getTenant();
        $category = $this->category();
        $balance = app(CommunityPayouts::class)->balance($category);

        $currencies = LedgerAccount::on('landlord')
            ->where('tenant_id', $tenant->id)
            ->whereNull('event_id')
            ->where('category', $category)
            ->where('code', LedgerAccount::CODE_ORGANIZER_PAYABLE)
            ->orderBy('currency')
            ->pluck('currency')
            ->unique()
            ->values();

        $accounts = TenantPayoutAccount::where('tenant_id', $tenant->id)->orderByDesc('created_at')->get();

        $payouts = $this->payouts($tenant)->where('category', $category)
            ->with('payoutAccount')
            ->latest()
            ->limit(100)
            ->get();

        return Inertia::render('Tenant/Finance/Payouts', [
            'category' => $category->value,
            'categories' => ['dues', 'giving'],
            'balances' => $currencies->map(fn (string $currency): array => [
                'currency' => $currency,
                'payable' => $balance->payable($tenant, $currency),
                'in_flight' => $balance->inFlight($tenant, $currency),
                'available' => $balance->available($tenant, $currency),
                'pending_refunds' => $category === LedgerCategoryEnum::Giving ? $balance->pendingRefundReservations($tenant, $currency) : 0,
            ])->values(),
            'accounts' => $accounts->map(fn (TenantPayoutAccount $a): array => [
                'id' => $a->id,
                'type' => $a->type,
                'label' => $a->label,
                'account_name' => $a->account_name,
                'masked_account_number' => $a->maskedAccountNumber(),
                'is_verified' => $a->is_verified,
            ])->values(),
            'payouts' => $payouts->map(fn (EventPayout $p): array => [
                'id' => $p->id,
                'amount' => $p->amount,
                'currency' => $p->currency,
                'status' => $p->status,
                'scheduled_at' => $p->scheduled_at?->toIso8601String(),
                'paid_at' => $p->paid_at?->toIso8601String(),
                'created_at' => $p->created_at?->toIso8601String(),
                'note' => $p->note,
                'failure_reason' => $p->failure_reason,
                'transfer_fee_amount' => $p->transfer_fee_amount,
                'net_paid_amount' => $p->net_paid_amount,
                'payout_account_label' => $p->payoutAccount?->label,
                'payout_account_masked_number' => $p->payoutAccount?->maskedAccountNumber(),
            ])->values(),
            'canManagePayouts' => (bool) request()->user()?->can('manage payouts'),
            'canManagePaymentSettings' => (bool) request()->user()?->can('manage payment settings'),
        ]);
    }

    /**
     * Reserve part of the dues balance for a payout.
     *
     * The tenant's dues Organizer Payable account for the currency is locked
     * for the check-then-create sequence, so two concurrent requests are
     * served one after the other and the second sees the first's payout as
     * in flight instead of both spending the same balance.
     */
    public function store(StoreDuesPayoutRequest $request, CommunityPayouts $payouts): JsonResponse
    {
        $validated = $request->validated();
        $category = LedgerCategoryEnum::from($validated['category'] ?? 'dues');
        $payout = $payouts->reserve($this->getTenant(), $request->user(), $category, $validated);

        return response()->json(['id' => $payout->id], 201);
    }

    /**
     * Send a scheduled or failed dues payout. The payout row is locked for
     * the whole check-send-write sequence, as for event payouts.
     *
     * A failed payout no longer counts as in flight, so its money may since
     * have been reserved by another payout. Re-sending one therefore checks
     * the balance again under the same payable-account lock that store()
     * takes. Locks are always taken in the same order, payable account first
     * and then the payout, so a send and a store cannot deadlock.
     */
    public function send(string $subdomain, string $payout, CommunityPayouts $payouts): JsonResponse
    {
        $tenant = $this->getTenant();
        $result = $payouts->send($tenant, request()->user(), $this->payouts($tenant)->whereKey($payout)->firstOrFail());

        return response()->json(Arr::except($result, 'http_status'), $result['http_status']);
    }

    /**
     * Release a dues payout Paystack is holding for a one-time code.
     */
    public function finalize(\App\Http\Requests\Tenant\Giving\FinalizeCommunityPayoutRequest $request, string $subdomain, string $payout, CommunityPayouts $payouts): JsonResponse
    {
        $tenant = $this->getTenant();
        $result = $payouts->finalize($tenant, $request->user(), $this->payouts($tenant)->whereKey($payout)->firstOrFail(), $request->validated('otp'));

        return $result['released']
            ? response()->json(['message' => $result['message'], 'status' => EventPayout::STATUS_PROCESSING])
            : response()->json(['message' => $result['message']], $result['http_status']);
    }

    /**
     * @return Builder<EventPayout>
     */
    private function payouts(Tenant $tenant): Builder
    {
        return EventPayout::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('category', [LedgerCategoryEnum::Dues, LedgerCategoryEnum::Giving]);
    }

    private function category(): LedgerCategoryEnum
    {
        $input = request()->validate(['category' => ['sometimes', Rule::in(['dues', 'giving'])]]);

        return LedgerCategoryEnum::from($input['category'] ?? 'dues');
    }

    private function getTenant(): Tenant
    {
        $tenant = app(TenantContext::class)->getTenant();

        if (! $tenant) {
            abort(403, 'Tenant context not resolved.');
        }

        return $tenant;
    }
}
