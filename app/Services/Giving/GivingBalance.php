<?php

declare(strict_types=1);

namespace App\Services\Giving;

use App\Enum\LedgerCategoryEnum;
use App\Models\EventPayout;
use App\Models\GivingAdjustment;
use App\Models\GivingPaymentAttempt;
use App\Models\LedgerAccount;
use App\Models\Tenant;
use App\Services\Dues\DuesBalance;
use Illuminate\Database\Eloquent\Builder;

final class GivingBalance
{
    public function available(Tenant $tenant, string $currency): int
    {
        $inFlight = $this->inFlight($tenant, $currency);
        $refunds = $this->pendingRefundReservations($tenant, $currency);

        return max(0, $this->payable($tenant, $currency) - $inFlight - $refunds);
    }

    public function payable(Tenant $tenant, string $currency): int
    {
        return $this->accountQuery($tenant, $currency)->first()?->currentBalance() ?? 0;
    }

    public function inFlight(Tenant $tenant, string $currency): int
    {
        return (int) EventPayout::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('category', LedgerCategoryEnum::Giving)->where('currency', $currency)->whereIn('status', DuesBalance::IN_FLIGHT_STATUSES)->sum('amount');
    }

    public function pendingRefundReservations(Tenant $tenant, string $currency): int
    {
        $attempts = GivingPaymentAttempt::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('currency', $currency)->where('settlement_mode', Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT);

        return GivingAdjustment::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('kind', 'refund')->whereIn('status', ['requested', 'processing', 'unknown'])
            ->where(fn ($query) => $query->whereIn('gift_id', (clone $attempts)->select('gift_id'))->orWhereIn('event_contribution_id', (clone $attempts)->select('event_contribution_id')))
            ->get(['principal', 'commission_reversal'])->sum(fn (GivingAdjustment $adjustment): int => max(0, $adjustment->principal - $adjustment->commission_reversal));
    }

    public function lockPayableAccount(Tenant $tenant, string $currency): LedgerAccount
    {
        return $this->accountQuery($tenant, $currency)->lockForUpdate()->firstOrFail();
    }

    /** @return Builder<LedgerAccount> */
    private function accountQuery(Tenant $tenant, string $currency): Builder
    {
        return LedgerAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNull('event_id')->where('category', LedgerCategoryEnum::Giving)->where('currency', $currency)->where('code', LedgerAccount::CODE_ORGANIZER_PAYABLE);
    }
}
