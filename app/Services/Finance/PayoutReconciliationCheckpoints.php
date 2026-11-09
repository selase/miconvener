<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\PayoutReconciliationCheckpoint;
use Illuminate\Validation\ValidationException;

final class PayoutReconciliationCheckpoints
{
    public function block(string $accountFingerprint, string $reason, int|string|null $reviewer = null): void
    {
        (new PayoutReconciliationCheckpoint)->getConnection()->transaction(function () use ($accountFingerprint, $reason, $reviewer): void {
            \App\Models\ProviderPayoutLiquidity::query()->firstOrCreate(['account_fingerprint' => $accountFingerprint],
                ['remaining' => 0, 'observed_balance' => 0, 'observed_at' => now(), 'blocked' => true]);
            $budget = \App\Models\ProviderPayoutLiquidity::query()->lockForUpdate()->findOrFail($accountFingerprint);
            $budget->update(['blocked' => true]);
            $latest = PayoutReconciliationCheckpoint::query()->where('account_fingerprint', $accountFingerprint)->where('scope_key', '*')->latest('observed_at')->latest('id')->first();
            if ($latest?->status === 'blocked' && ($latest->evidence['reason'] ?? null) === $reason) {
                return;
            }
            PayoutReconciliationCheckpoint::query()->create(['account_fingerprint' => $accountFingerprint, 'scope_key' => '*',
                'observed_at' => now(), 'status' => 'blocked', 'reviewed_by' => $reviewer, 'created_at' => now(),
                'evidence' => ['unresolved_count' => 1, 'reason' => $reason]]);
        });
    }

    public function assertFresh(string $accountFingerprint, string $scopeKey): void
    {
        foreach (['*', $scopeKey] as $scope) {
            $last = PayoutReconciliationCheckpoint::query()->where('account_fingerprint', $accountFingerprint)->where('scope_key', $scope)
                ->orderByDesc('observed_at')->orderByDesc('id')->first();
            $evidence = $last->evidence ?? [];
            if ($last === null || $last->status !== 'matched' || $last->observed_at->lte(now()->subDay()) || $last->observed_at->gt(now())
                || ($evidence['ledger_checked'] ?? false) !== true || ($evidence['provider_checked'] ?? false) !== true
                || ($evidence['unresolved_count'] ?? -1) !== 0) {
                throw ValidationException::withMessages(['schedule' => 'Reconciliation is overdue or needs finance review. No new payout can be prepared or submitted.']);
            }
        }
    }
}
