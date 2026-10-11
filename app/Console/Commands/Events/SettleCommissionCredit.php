<?php

declare(strict_types=1);

namespace App\Console\Commands\Events;

use App\Models\Tenant;
use App\Models\TenantCommissionCredit;
use App\Services\Finance\CommissionCredit;
use Illuminate\Console\Command;

final class SettleCommissionCredit extends Command
{
    protected $signature = 'payments:commission-credits {tenant_slug? : Show or settle one organizer} {--currency=GHS} {--settle= : Minor units to pay out and clear, or "all"}';

    protected $description = 'Superadmin-only: list the commission credit owed back to organizers, or record paying one out after you have transferred it';

    public function handle(CommissionCredit $credits): int
    {
        $slug = $this->argument('tenant_slug');

        if ($slug === null) {
            $rows = TenantCommissionCredit::query()->where('amount', '>', 0)->orderByDesc('amount')->get();

            $this->table(['Organizer', 'Currency', 'Credit (minor units)'], $rows->map(fn (TenantCommissionCredit $credit): array => [
                Tenant::find($credit->tenant_id)->slug ?? $credit->tenant_id,
                $credit->currency,
                $credit->amount,
            ])->all());

            return self::SUCCESS;
        }

        $tenant = Tenant::where('slug', $slug)->firstOrFail();
        $currency = mb_strtoupper((string) $this->option('currency'));
        $balance = $credits->balance($tenant, $currency);

        if ($this->option('settle') === null) {
            $this->line("{$tenant->slug} is owed {$balance} {$currency} (minor units) in commission credit.");

            return self::SUCCESS;
        }

        $amount = $this->option('settle') === 'all' ? $balance : (int) $this->option('settle');

        if ($amount < 1) {
            $this->error('Nothing to settle: give an amount above zero, or "all" with a balance.');

            return self::FAILURE;
        }

        $taken = $credits->settle($tenant, $currency, $amount);

        if ($taken < $amount) {
            $this->warn("Only {$taken} of {$amount} was available, so {$taken} is recorded.");
        }

        $this->info("Recorded {$taken} {$currency} paid out; {$credits->balance($tenant, $currency)} remains.");

        return self::SUCCESS;
    }
}
