<?php

declare(strict_types=1);

namespace App\Services\Dues;

use App\Enum\LedgerCategoryEnum;
use App\Models\EventPayout;
use App\Models\LedgerAccount;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;

/**
 * How much collected dues an organisation can still ask to be paid out, in
 * minor units of one currency.
 *
 * The dues Organizer Payable is what the platform owes the organisation; a
 * payout that has been confirmed has already been debited from it by
 * LedgerService::recordDuesPayout. What is left to subtract is every payout
 * still on its way out: scheduled, waiting for a one-time code, or processing.
 * A failed payout no longer holds money.
 *
 * The result is clamped at zero: commission owed on an own-gateway dues
 * payment is a debit to the payable, so the account can run negative, and a
 * negative balance must never be read as something to pay out.
 */
final class DuesBalance
{
    /**
     * Payout states whose amount is still committed and not yet in the ledger.
     *
     * @var list<string>
     */
    public const array IN_FLIGHT_STATUSES = [
        EventPayout::STATUS_SCHEDULED,
        EventPayout::STATUS_AWAITING_OTP,
        EventPayout::STATUS_PROCESSING,
    ];

    /**
     * In-flight payouts are read before the payable on purpose. A confirmed
     * transfer flips its payout to paid and debits the payable in one
     * transaction; if that commits between the two reads, this order sees the
     * payout still in flight and the payable already debited, which
     * understates the balance. The opposite order would overstate it.
     */
    public function available(Tenant $tenant, string $currency): int
    {
        $inFlight = $this->inFlight($tenant, $currency);

        return max(0, $this->payable($tenant, $currency) - $inFlight);
    }

    /**
     * The dues Organizer Payable balance, before payouts in flight.
     */
    public function payable(Tenant $tenant, string $currency): int
    {
        return $this->payableAccount($tenant, $currency)?->currentBalance() ?? 0;
    }

    /**
     * The sum of dues payouts committed but not yet confirmed.
     */
    public function inFlight(Tenant $tenant, string $currency): int
    {
        return (int) EventPayout::query()
            ->where('tenant_id', $tenant->id)
            ->where('category', LedgerCategoryEnum::Dues)
            ->where('currency', $currency)
            ->whereIn('status', self::IN_FLIGHT_STATUSES)
            ->sum('amount');
    }

    /**
     * Lock the dues Organizer Payable row for the rest of the current
     * transaction. Anything that checks the balance and then commits money
     * against it takes this lock first, so such checks run one at a time per
     * tenant and currency. The account must already exist: create it with
     * LedgerService::ensureAccountsExist before opening the transaction.
     */
    public function lockPayableAccount(Tenant $tenant, string $currency): LedgerAccount
    {
        return $this->payableAccountQuery($tenant, $currency)->lockForUpdate()->firstOrFail();
    }

    private function payableAccount(Tenant $tenant, string $currency): ?LedgerAccount
    {
        return $this->payableAccountQuery($tenant, $currency)->first();
    }

    /**
     * @return Builder<LedgerAccount>
     */
    private function payableAccountQuery(Tenant $tenant, string $currency): Builder
    {
        return LedgerAccount::on('landlord')
            ->where('tenant_id', $tenant->id)
            ->whereNull('event_id')
            ->where('category', LedgerCategoryEnum::Dues)
            ->where('code', LedgerAccount::CODE_ORGANIZER_PAYABLE)
            ->where('currency', $currency);
    }
}
