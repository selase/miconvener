<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Contracts\SettlementGateway;
use App\Models\EventPayout;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\OrganisationPayoutPolicy;
use App\Models\PayoutLiquidityAllocation;
use App\Models\PayoutReconciliationCheckpoint;
use App\Models\ProviderPayoutLiquidity;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PayoutFinancialAudit
{
    /** @return array<string,mixed> */
    public function inspect(int $externalCash = 0): array
    {
        if ($externalCash < 0) {
            throw ValidationException::withMessages(['audit' => 'Separately reconciled external cash cannot be negative.']);
        }
        $fingerprint = app(PayoutPolicies::class)->accountFingerprint();
        $before = $this->sourceDigest();
        $balance = app(ProviderLiquidity::class)->observe($fingerprint, 'GHS');
        $bookCash = LedgerAccount::withoutGlobalScopes()->where('currency', 'GHS')->whereIn('code', [LedgerAccount::CODE_GATEWAY_CLEARING, LedgerAccount::CODE_BANK])->get()
            ->sum(fn (LedgerAccount $account): int => $account->currentBalance());
        $last = PayoutReconciliationCheckpoint::query()->where('account_fingerprint', $fingerprint)->where('scope_key', '*')->where('status', 'matched')->latest('observed_at')->first();
        $query = EventPayout::withoutGlobalScopes()->where('currency', 'GHS')->when($last !== null, fn ($q) => $q->where(fn ($scope) => $scope->where('updated_at', '>=', $last->observed_at)->orWhereIn('status', ['scheduled', 'processing', 'awaiting_otp'])));
        $incomplete = (clone $query)->count() > 100;
        $unresolved = [];
        foreach ($query->orderBy('id')->limit(100)->get() as $payout) {
            $posting = LedgerTransaction::withoutGlobalScopes()->where('tenant_id', $payout->tenant_id)->where('transaction_type', 'payout')
                ->whereIn('reference', array_filter([$payout->provider_reference, 'PO-'.$payout->id]))->exists();
            if (in_array($payout->status, ['scheduled', 'processing', 'awaiting_otp'], true)) {
                $unresolved[] = $payout->id.($posting ? ': legacy posting or transfer needs review' : ': pending commitment');

                continue;
            }
            if ($payout->provider_reference === null) {
                if ($posting || $payout->status === 'paid') {
                    $unresolved[] = $payout->id.': historical settlement requires explicit review';
                }

                continue;
            }
            try {
                $verified = app(SettlementGateway::class)->verifyTransfer($payout->provider_reference);
                if (($verified['reference'] ?? null) !== $payout->provider_reference || ($verified['currency'] ?? null) !== $payout->currency
                    || ($verified['amount'] ?? null) !== $payout->requested_net_amount || ! is_int($verified['fee'])
                    || $verified['fee'] !== $payout->requested_transfer_fee_amount
                    || ($payout->status === 'paid' && ($verified['status'] !== 'success' || ! $posting))
                    || ($payout->status === 'reversed' && $verified['status'] !== 'reversed')
                    || ($payout->status === 'failed' && ! in_array($verified['status'], PayoutRelease::DEAD_TRANSFER_STATUSES, true))) {
                    $unresolved[] = $payout->id.': provider and recorded evidence differ';
                }
            } catch (Throwable) {
                $unresolved[] = $payout->id.': original transfer could not be verified';
            }
        }
        $digest = $this->sourceDigest();

        return ['account_fingerprint' => $fingerprint, 'observed_at' => now()->toIso8601String(), 'source_digest' => $digest,
            'provider_balance' => $balance['balance'], 'book_cash' => $bookCash, 'external_cash' => $externalCash,
            'unresolved' => $unresolved, 'scan_incomplete' => $incomplete,
            'matched' => ! $incomplete && $before === $digest && $unresolved === [] && $bookCash === $balance['balance'] - $externalCash];
    }

    /** Rechecks live sources; a browser or CLI report alone never certifies money.
     * @param  array<string,mixed>  $report
     */
    public function record(array $report, User $actor, string $statementReference, string $note): void
    {
        app(PayoutPolicies::class)->authorisePlatform($actor);
        if (mb_strlen($statementReference) < 5 || mb_strlen($statementReference) > 200 || mb_strlen($note) < 20 || mb_strlen($note) > 1000) {
            throw ValidationException::withMessages(['audit' => 'Provide a statement reference and an explanation of the reconciliation.']);
        }
        $fresh = $this->inspect((int) ($report['external_cash'] ?? 0));
        if (! $fresh['matched']) {
            app(PayoutReconciliationCheckpoints::class)->block($fresh['account_fingerprint'], 'Financial reconciliation has unresolved exceptions.', $actor->id);
        }
        if (! $fresh['matched'] || ($report['source_digest'] ?? null) !== $fresh['source_digest']) {
            throw ValidationException::withMessages(['audit' => 'Source evidence changed or reconciliation does not match. Resolve exceptions before certifying.']);
        }
        (new PayoutReconciliationCheckpoint)->getConnection()->transaction(function () use ($fresh, $actor, $statementReference, $note): void {
            $budget = ProviderPayoutLiquidity::query()->lockForUpdate()->find($fresh['account_fingerprint']);
            if ($this->sourceDigest() !== $fresh['source_digest']) {
                throw ValidationException::withMessages(['audit' => 'Financial records changed during review. Run a fresh reconciliation.']);
            }
            $scopes = OrganisationPayoutPolicy::query()->where('account_fingerprint', $fresh['account_fingerprint'])->where('status', 'approved')->get()
                ->map(fn (OrganisationPayoutPolicy $policy): string => $policy->tenant_id.':'.$policy->category)->push('*')->unique();
            foreach ($scopes as $scope) {
                PayoutReconciliationCheckpoint::query()->create(['account_fingerprint' => $fresh['account_fingerprint'], 'scope_key' => $scope,
                    'observed_at' => now(), 'status' => 'matched', 'reviewed_by' => $actor->id, 'created_at' => now(),
                    'evidence' => ['ledger_checked' => true, 'provider_checked' => true, 'unresolved_count' => 0,
                        'source_digest' => $fresh['source_digest'], 'provider_balance' => $fresh['provider_balance'], 'book_cash' => $fresh['book_cash'],
                        'external_cash' => $fresh['external_cash'], 'statement_reference' => $statementReference, 'review_note' => $note]]);
            }
            if ($budget !== null && is_int(config('services.payout_schedules.cash_buffer'))) {
                $budget->update(['remaining' => max(0, $fresh['provider_balance'] - $fresh['external_cash'] - config('services.payout_schedules.cash_buffer')),
                    'observed_balance' => $fresh['provider_balance'], 'observed_at' => now(), 'blocked' => false]);
                PayoutLiquidityAllocation::query()->where('account_fingerprint', $fresh['account_fingerprint'])->whereNull('reconciled_at')->update(['reconciled_at' => now()]);
            }
        });
    }

    /** @param array<string,mixed> $report */
    public function recordException(array $report, User $actor): void
    {
        app(PayoutPolicies::class)->authorisePlatform($actor);
        $fresh = $this->inspect((int) ($report['external_cash'] ?? 0));
        if (! $fresh['matched']) {
            app(PayoutReconciliationCheckpoints::class)->block($fresh['account_fingerprint'], 'Financial reconciliation has unresolved exceptions.', $actor->id);
        }
    }

    public function sourceDigest(): string
    {
        $context = hash_init('sha256');
        EventPayout::withoutGlobalScopes()->select(['id', 'status', 'amount', 'provider_reference', 'updated_at'])->orderBy('id')
            ->chunkById(200, function ($payouts) use ($context): void {
                foreach ($payouts as $payout) {
                    hash_update($context, json_encode($payout->toArray(), JSON_THROW_ON_ERROR));
                }
            });
        hash_update($context, json_encode([LedgerEntry::withoutGlobalScopes()->count(), LedgerEntry::withoutGlobalScopes()->sum('amount'),
            PayoutLiquidityAllocation::query()->count(), PayoutReconciliationCheckpoint::query()->count()], JSON_THROW_ON_ERROR));

        return hash_final($context);
    }
}
