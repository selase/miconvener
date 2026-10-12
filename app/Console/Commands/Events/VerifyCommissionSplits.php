<?php

declare(strict_types=1);

namespace App\Console\Commands\Events;

use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Payment\CommissionSplitVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class VerifyCommissionSplits extends Command
{
    protected $signature = 'payments:verify-commission-splits';

    protected $description = 'Re-check every own-gateway Paystack tenant\'s commission subaccount, so a deactivated or repointed one is caught before the next sale';

    public function handle(CommissionSplitVerifier $verifier): int
    {
        if (! $verifier->isEnforced()) {
            $this->line('Commission splitting is not enforced; nothing to check.');

            return self::SUCCESS;
        }

        $broken = 0;

        TenantPaymentGateway::query()
            ->where('provider', 'paystack')
            ->where('is_active', true)
            ->whereIn('tenant_id', Tenant::query()->where('settlement_mode', Tenant::SETTLEMENT_MODE_OWN_GATEWAY)->select('id'))
            ->each(function (TenantPaymentGateway $gateway) use ($verifier, &$broken): void {
                if ($gateway->commissionSubaccountCode() === null) {
                    return;
                }

                $check = $verifier->recheck($gateway);

                if (! $check->ok && ! $check->inconclusive) {
                    $broken++;
                    Log::warning('A tenant commission subaccount failed its nightly check', [
                        'tenant' => $gateway->tenant_id,
                        'reason' => $check->reason,
                    ]);
                }
            });

        $this->info("Checked commission subaccounts; {$broken} failed.");

        return self::SUCCESS;
    }
}
