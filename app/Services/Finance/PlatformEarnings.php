<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Tenant;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * What MiConvener itself earned in a period, from the two places it is
 * recorded:
 *
 * - the ledger's Platform Commission Revenue account (4000): commission on
 *   ticket sales and contributions, marketplace commission and buyer fees.
 *   Credits are earnings; debits (refunds, reversed commission) take them back.
 * - successful billing payments (transactions): plans, renewals, add-ons,
 *   marketplace promotions and AI tokens paid to us.
 *
 * Amounts are in pesewas. Only GHS is reported; anything else is counted
 * separately so it is never silently added to cedi totals.
 */
final class PlatformEarnings
{
    /**
     * @var array<string, string>
     */
    public const array COMMISSION_LABELS = [
        LedgerTransaction::TYPE_TICKET_SALE => 'Ticket commission',
        LedgerTransaction::TYPE_OFFLINE_TICKET_SALE => 'Ticket commission (paid at the door)',
        LedgerTransaction::TYPE_CONTRIBUTION => 'Contribution fees',
        'marketplace_booking' => 'Marketplace commission and buyer fees',
        LedgerTransaction::TYPE_REFUND => 'Refunded commission',
        LedgerTransaction::TYPE_ADJUSTMENT => 'Adjustments',
    ];

    /**
     * @return array{
     *     lines: list<array{source: string, label: string, amount: int, count: int}>,
     *     commission_total: int,
     *     billing_total: int,
     *     total: int,
     *     by_tenant: list<array{tenant_id: string, name: string, amount: int}>,
     *     other_currencies: int
     * }
     */
    public function between(CarbonInterface $from, CarbonInterface $to): array
    {
        $commission = $this->commissionLines($from, $to);
        $billing = $this->billingLines($from, $to);

        $byTenant = [];
        foreach ([$commission['by_tenant'], $billing['by_tenant']] as $amounts) {
            foreach ($amounts as $tenantId => $amount) {
                $byTenant[$tenantId] = ($byTenant[$tenantId] ?? 0) + $amount;
            }
        }
        arsort($byTenant);
        $byTenant = collect(array_slice($byTenant, 0, 10, true));

        $names = Tenant::query()->whereIn('id', $byTenant->keys())->pluck('name', 'id');

        $commissionTotal = array_sum(array_column($commission['lines'], 'amount'));
        $billingTotal = array_sum(array_column($billing['lines'], 'amount'));

        return [
            'lines' => [...$commission['lines'], ...$billing['lines']],
            'commission_total' => $commissionTotal,
            'billing_total' => $billingTotal,
            'total' => $commissionTotal + $billingTotal,
            'by_tenant' => $byTenant->map(fn (int $amount, int|string $tenantId): array => [
                'tenant_id' => (string) $tenantId,
                'name' => (string) ($names[$tenantId] ?? 'Deleted organisation'),
                'amount' => $amount,
            ])->values()->all(),
            'other_currencies' => $billing['other_currencies'],
        ];
    }

    /**
     * @return array{lines: list<array{source: string, label: string, amount: int, count: int}>, by_tenant: array<string, int>}
     */
    private function commissionLines(CarbonInterface $from, CarbonInterface $to): array
    {
        $entries = LedgerEntry::query()
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
            ->join('ledger_transactions', 'ledger_transactions.id', '=', 'ledger_entries.transaction_id')
            ->where('ledger_accounts.code', LedgerAccount::CODE_PLATFORM_REVENUE)
            ->where('ledger_entries.currency', 'GHS')
            ->whereBetween('ledger_transactions.posted_at', [$from, $to])
            ->get(['ledger_entries.amount', 'ledger_entries.direction', 'ledger_transactions.transaction_type', 'ledger_transactions.tenant_id', 'ledger_transactions.id as ledger_transaction_id']);

        $signed = $entries->map(fn (LedgerEntry $entry): array => [
            'type' => (string) $entry->getAttribute('transaction_type'),
            'tenant_id' => (string) $entry->getAttribute('tenant_id'),
            'transaction' => (string) $entry->getAttribute('ledger_transaction_id'),
            'amount' => $entry->direction === LedgerEntry::DIRECTION_CREDIT ? (int) $entry->amount : -(int) $entry->amount,
        ]);

        $lines = $signed->groupBy('type')->map(fn (Collection $rows, string $type): array => [
            'source' => 'Commission',
            'label' => self::COMMISSION_LABELS[$type] ?? ucfirst(str_replace('_', ' ', $type)),
            'amount' => (int) $rows->sum('amount'),
            'count' => $rows->unique('transaction')->count(),
        ])->values();

        $byTenant = $signed->groupBy('tenant_id')->map(fn (Collection $rows): int => (int) $rows->sum('amount'))->all();

        return ['lines' => $lines->all(), 'by_tenant' => $byTenant];
    }

    /**
     * @return array{lines: list<array{source: string, label: string, amount: int, count: int}>, by_tenant: array<string, int>, other_currencies: int}
     */
    private function billingLines(CarbonInterface $from, CarbonInterface $to): array
    {
        $payments = Transaction::query()
            ->successful()
            ->where('provider', '!=', 'dev_bypass')
            ->whereBetween('created_at', [$from, $to])
            ->get(['tenant_id', 'amount', 'currency', 'type', 'meta']);

        $cedis = $payments->filter(fn (Transaction $payment): bool => mb_strtolower((string) $payment->currency) === 'ghs');

        $lines = $cedis->groupBy(fn (Transaction $payment): string => $this->billingLabel($payment))
            ->map(fn (Collection $rows, string $label): array => [
                'source' => 'Billing',
                'label' => $label,
                'amount' => (int) $rows->sum('amount'),
                'count' => $rows->count(),
            ])->values();

        $byTenant = $cedis->groupBy('tenant_id')->map(fn (Collection $rows): int => (int) $rows->sum('amount'))->all();

        return ['lines' => $lines->all(), 'by_tenant' => $byTenant, 'other_currencies' => $payments->count() - $cedis->count()];
    }

    private function billingLabel(Transaction $payment): string
    {
        $meta = (array) $payment->meta;

        return match ($meta['type'] ?? null) {
            'tenant_addon' => str_starts_with((string) ($meta['addon_type'] ?? ''), 'shop_')
                ? 'Marketplace promotions'
                : 'Add-ons: '.str_replace('_', ' ', (string) ($meta['addon_type'] ?? 'other')),
            'plan_renewal' => 'Plan renewals',
            'llm_token_purchase' => 'AI token packs',
            'invoice_payment', 'metered_invoice' => 'Invoices',
            default => isset($meta['package_id']) || isset($meta['provider_plan_id']) ? 'New plans and upgrades' : 'Other payments',
        };
    }
}
