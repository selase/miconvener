<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enum\LedgerCategoryEnum;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Tenant;
use App\Models\TenantCommissionCredit;
use Illuminate\Support\Facades\DB;

/**
 * The commission the platform owes an organizer back, held as a balance that
 * their later sales net against instead of moving money for every refund. All
 * amounts are minor units; a balance is kept per currency.
 */
final class CommissionCredit
{
    public function balance(Tenant $tenant, string $currency): int
    {
        return (int) TenantCommissionCredit::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('currency', mb_strtoupper($currency))
            ->value('amount');
    }

    /**
     * @return array<string, int> currency => balance, for balances above zero
     */
    public function balances(Tenant $tenant): array
    {
        return TenantCommissionCredit::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('amount', '>', 0)
            ->orderBy('currency')
            ->pluck('amount', 'currency')
            ->map(fn (mixed $amount): int => (int) $amount)
            ->all();
    }

    public function grant(Tenant $tenant, string $currency, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        DB::connection('landlord')->transaction(function () use ($tenant, $currency, $amount): void {
            $credit = TenantCommissionCredit::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('currency', mb_strtoupper($currency))
                ->lockForUpdate()
                ->first()
                ?? TenantCommissionCredit::query()->create([
                    'tenant_id' => $tenant->id,
                    'currency' => mb_strtoupper($currency),
                    'amount' => 0,
                ]);

            $credit->increment('amount', $amount);
        });
    }

    /**
     * Takes up to $amount from the balance and returns what it actually took,
     * so two sales racing for the same credit can't both spend it.
     */
    public function consume(Tenant $tenant, string $currency, int $amount): int
    {
        if ($amount <= 0) {
            return 0;
        }

        return DB::connection('landlord')->transaction(function () use ($tenant, $currency, $amount): int {
            $credit = TenantCommissionCredit::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('currency', mb_strtoupper($currency))
                ->lockForUpdate()
                ->first();

            $taken = min($amount, (int) $credit?->amount);

            if ($credit && $taken > 0) {
                $credit->decrement('amount', $taken);
            }

            return $taken;
        });
    }

    /**
     * Records that the organizer has been paid $amount of their credit, after
     * the money was sent outside the app. Takes at most what is owed and
     * returns what was actually recorded.
     */
    public function settle(Tenant $tenant, string $currency, int $amount): int
    {
        $taken = $this->consume($tenant, $currency, $amount);

        if ($taken < 1) {
            return 0;
        }

        app(LedgerService::class)->postTransaction(
            $tenant,
            null,
            LedgerTransaction::TYPE_PAYOUT,
            "Commission credit paid out to {$tenant->name}",
            'CREDIT-SETTLE-'.$tenant->id.'-'.now()->format('YmdHisu'),
            [
                ['code' => LedgerAccount::CODE_ORGANIZER_PAYABLE, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => $taken, 'description' => 'Commission owed back, paid out'],
                ['code' => LedgerAccount::CODE_BANK, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => $taken, 'description' => 'Paid to the organizer'],
            ],
            null,
            mb_strtoupper($currency),
            LedgerCategoryEnum::CommissionCredit,
        );

        return $taken;
    }
}
