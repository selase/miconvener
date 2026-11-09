<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\PayoutReconciliationCheckpoint;
use Illuminate\Validation\ValidationException;

final class PayoutReconciliationCheckpoints
{
    public function assertFresh(string $accountFingerprint, string $scopeKey): void
    {
        foreach (['*', $scopeKey] as $scope) {
            $last = PayoutReconciliationCheckpoint::query()->where('account_fingerprint', $accountFingerprint)->where('scope_key', $scope)
                ->orderByDesc('observed_at')->orderByDesc('id')->first();
            if ($scope === '*' && $last === null) {
                continue;
            }
            $evidence = $last?->evidence ?? [];
            if ($last === null || $last->status !== 'matched' || $last->observed_at->lte(now()->subDay()) || $last->observed_at->gt(now())
                || ($evidence['ledger_checked'] ?? false) !== true || ($evidence['provider_checked'] ?? false) !== true
                || ($evidence['unresolved_count'] ?? -1) !== 0) {
                throw ValidationException::withMessages(['schedule' => 'Reconciliation is overdue or needs finance review. No new payout can be prepared or submitted.']);
            }
        }
    }
}
