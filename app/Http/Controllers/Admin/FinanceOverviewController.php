<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EventPayout;
use App\Models\TenantCommissionCredit;
use App\Models\TenantPaymentGateway;
use App\Services\Finance\PlatformEarnings;
use App\Services\Payment\CommissionDestinations;
use Illuminate\Contracts\View\View;

/**
 * Where platform finance staff land: the money this month, what is waiting on
 * them, and what the platform owes back, with a way into each.
 */
final class FinanceOverviewController extends Controller
{
    public function __invoke(PlatformEarnings $earnings, CommissionDestinations $destinations): View
    {
        $this->authorize('view platform finance');

        $waiting = EventPayout::query()->withoutGlobalScopes()
            ->whereIn('status', [EventPayout::STATUS_AWAITING_OTP, EventPayout::STATUS_FAILED]);

        return view('admin.finance.overview', [
            'earnings' => $earnings->between(now()->startOfMonth(), now()),
            'payoutsAwaitingCode' => (clone $waiting)->where('status', EventPayout::STATUS_AWAITING_OTP)->count(),
            'payoutsFailed' => (clone $waiting)->where('status', EventPayout::STATUS_FAILED)->count(),
            'paid30' => (int) EventPayout::query()->withoutGlobalScopes()->where('status', EventPayout::STATUS_PAID)->where('paid_at', '>=', now()->subDays(30))->sum('amount'),
            'creditsOwed' => TenantCommissionCredit::query()->withoutGlobalScopes()->where('amount', '>', 0)
                ->selectRaw('currency, sum(amount) as total, count(*) as organisations')
                ->groupBy('currency')->orderBy('currency')->get(),
            'commissionAccounts' => count($destinations->current()),
            'organisersOnSplit' => TenantPaymentGateway::query()->withoutGlobalScopes()
                ->where('provider', 'paystack')->where('is_active', true)
                ->whereRaw("(meta->'commission_split'->>'ok') = 'true'")->count(),
        ]);
    }
}
