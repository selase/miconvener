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
use App\Services\Finance\LedgerService;
use App\Services\Finance\PayoutRelease;
use App\Services\Finance\PayoutSender;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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

        $currencies = LedgerAccount::on('landlord')
            ->where('tenant_id', $tenant->id)
            ->whereNull('event_id')
            ->where('category', LedgerCategoryEnum::Dues)
            ->where('code', LedgerAccount::CODE_ORGANIZER_PAYABLE)
            ->orderBy('currency')
            ->pluck('currency')
            ->unique()
            ->values();

        $accounts = TenantPayoutAccount::where('tenant_id', $tenant->id)->orderByDesc('created_at')->get();

        $payouts = $this->duesPayouts($tenant)
            ->with('payoutAccount')
            ->latest()
            ->limit(100)
            ->get();

        return Inertia::render('Tenant/Finance/Payouts', [
            'balances' => $currencies->map(fn (string $currency): array => [
                'currency' => $currency,
                'payable' => $duesBalance->payable($tenant, $currency),
                'in_flight' => $duesBalance->inFlight($tenant, $currency),
                'available' => $duesBalance->available($tenant, $currency),
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
    public function store(StoreDuesPayoutRequest $request, LedgerService $ledgerService, DuesBalance $duesBalance): JsonResponse
    {
        $tenant = $this->getTenant();
        $validated = $request->validated();
        $currency = (string) $validated['currency'];

        $ledgerService->ensureAccountsExist($tenant, null, $currency, LedgerCategoryEnum::Dues);

        return DB::transaction(function () use ($tenant, $validated, $currency, $duesBalance): JsonResponse {
            $duesBalance->lockPayableAccount($tenant, $currency);

            $amount = (int) $validated['amount'];
            $available = $duesBalance->available($tenant, $currency);

            if ($amount > $available) {
                return response()->json(['message' => "Requested amount ({$amount}) exceeds the available dues balance ({$available})."], 422);
            }

            $payout = EventPayout::create([
                'tenant_id' => $tenant->id,
                'event_id' => null,
                'category' => LedgerCategoryEnum::Dues,
                'payout_account_id' => $validated['payout_account_id'],
                'amount' => $amount,
                'currency' => $currency,
                'status' => EventPayout::STATUS_SCHEDULED,
                'scheduled_at' => $validated['scheduled_at'] ?? null,
                'note' => $validated['note'] ?? null,
            ]);

            return response()->json(['id' => $payout->id], 201);
        });
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
    public function send(string $subdomain, string $payout, PayoutSender $payoutSender, LedgerService $ledgerService, DuesBalance $duesBalance): JsonResponse
    {
        $this->authorize('manage payouts');
        $tenant = $this->getTenant();
        $currency = (string) $this->duesPayouts($tenant)->whereKey($payout)->firstOrFail()->currency;

        $ledgerService->ensureAccountsExist($tenant, null, $currency, LedgerCategoryEnum::Dues);

        return DB::transaction(function () use ($tenant, $payout, $currency, $payoutSender, $duesBalance): JsonResponse {
            $duesBalance->lockPayableAccount($tenant, $currency);
            $payoutModel = $this->duesPayouts($tenant)->whereKey($payout)->lockForUpdate()->firstOrFail();

            if (! in_array($payoutModel->status, [EventPayout::STATUS_SCHEDULED, EventPayout::STATUS_FAILED], true)) {
                return response()->json(['message' => 'Only a scheduled or failed payout can be sent.'], 422);
            }

            if ($payoutModel->status === EventPayout::STATUS_FAILED) {
                $available = $duesBalance->available($tenant, $currency);

                if ($payoutModel->amount > $available) {
                    return response()->json(['message' => "This payout ({$payoutModel->amount}) is more than the available dues balance ({$available}), so it cannot be sent again."], 422);
                }
            }

            $result = $payoutSender->send($payoutModel, $currency);

            return response()->json(Arr::except($result, 'http_status'), $result['http_status']);
        });
    }

    /**
     * Release a dues payout Paystack is holding for a one-time code.
     */
    public function finalize(Request $request, string $subdomain, string $payout, PayoutRelease $payoutRelease): JsonResponse
    {
        $this->authorize('manage payouts');
        $tenant = $this->getTenant();

        $validated = $request->validate([
            'otp' => ['required', 'string', 'max:32'],
        ]);

        $result = $payoutRelease->release($this->duesPayouts($tenant)->whereKey($payout)->firstOrFail(), $validated['otp']);

        return $result['released']
            ? response()->json(['message' => $result['message'], 'status' => EventPayout::STATUS_PROCESSING])
            : response()->json(['message' => $result['message']], $result['http_status']);
    }

    /**
     * @return Builder<EventPayout>
     */
    private function duesPayouts(Tenant $tenant): Builder
    {
        return EventPayout::query()
            ->where('tenant_id', $tenant->id)
            ->where('category', LedgerCategoryEnum::Dues);
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
