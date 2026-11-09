<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\EventPayout;
use App\Models\PayoutLiquidityAllocation;
use App\Models\ProviderPayoutLiquidity;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Available provider cash bounds submissions; it never creates tenant entitlement. */
final class ProviderLiquidity
{
    /** @return array{balance:int,account_fingerprint:string} */
    public function refresh(string $accountFingerprint, string $currency, ?string $excludeUnsubmittedPayout = null): array
    {
        $this->configured();
        if ($currency !== 'GHS' || ! hash_equals($accountFingerprint, app(PayoutPolicies::class)->accountFingerprint())) {
            $this->refuse('The original settlement account must be reconciled before sending.');
        }
        app(PayoutReconciliationCheckpoints::class)->assertFresh($accountFingerprint, '*');
        $amount = $this->observe($accountFingerprint, $currency)['balance'];
        (new ProviderPayoutLiquidity)->getConnection()->transaction(function () use ($accountFingerprint, $amount, $excludeUnsubmittedPayout): void {
            $allocatedIds = PayoutLiquidityAllocation::query()->select('payout_id');
            $legacy = (int) EventPayout::withoutGlobalScopes()->whereIn('status', ['processing', 'awaiting_otp'])->whereNotIn('id', $allocatedIds)
                ->when($excludeUnsubmittedPayout !== null, fn ($q) => $q->whereKeyNot($excludeUnsubmittedPayout))->sum('amount');
            $external = \App\Models\PayoutReconciliationCheckpoint::query()->where('account_fingerprint', $accountFingerprint)->where('scope_key', '*')->where('status', 'matched')->latest('observed_at')->first()?->evidence['external_cash'] ?? 0;
            $bound = max(0, $amount - (int) $external - (int) config('services.payout_schedules.cash_buffer') - $legacy);
            ProviderPayoutLiquidity::query()->firstOrCreate(['account_fingerprint' => $accountFingerprint], ['remaining' => $bound,
                'observed_balance' => $amount, 'observed_at' => now(), 'blocked' => false]);
            $budget = ProviderPayoutLiquidity::query()->lockForUpdate()->findOrFail($accountFingerprint);
            $budget->update(['remaining' => min($budget->remaining, $bound), 'observed_balance' => $amount, 'observed_at' => now()]);
        });

        return ['balance' => $amount, 'account_fingerprint' => $accountFingerprint];
    }

    /** @return array{balance:int,account_fingerprint:string} */
    public function observe(string $accountFingerprint, string $currency): array
    {
        if ($currency !== 'GHS' || ! hash_equals($accountFingerprint, app(PayoutPolicies::class)->accountFingerprint())) {
            $this->refuse('The original GHS settlement account must be used for reconciliation.');
        }
        try {
            $response = Http::withToken((string) config('services.settlement.paystack.secret_key'))->connectTimeout(3)->timeout(10)->get('https://api.paystack.co/balance')->throw();
            $row = collect((array) $response->json('data', []))->first(fn ($value): bool => is_array($value) && ($value['currency'] ?? '') === 'GHS');
            if ($response->json('status') !== true || ! is_array($row) || ! is_int($row['balance'] ?? null) || $row['balance'] < 0) {
                $this->refuse('The provider has not confirmed an available GHS balance.');
            }
            $amount = $row['balance'];
        } catch (Throwable) {
            $this->refuse('Provider liquidity could not be verified. No new transfer was authorised.');
        }

        return ['balance' => $amount, 'account_fingerprint' => $accountFingerprint];
    }

    public function claim(EventPayout $payout): void
    {
        $this->configured();
        $fingerprint = app(PayoutPolicies::class)->accountFingerprint();
        $payout->getConnection()->transaction(function () use ($payout, $fingerprint): void {
            $budget = ProviderPayoutLiquidity::query()->lockForUpdate()->find($fingerprint);
            if ($budget === null || $budget->blocked || $budget->observed_at->lte(now()->subSeconds(60)) || $payout->currency !== 'GHS'
                || $payout->amount < 1 || $payout->provider_reference === null || $payout->status !== 'processing') {
                $this->refuse('A fresh, unblocked provider liquidity observation is required.');
            }
            if (PayoutLiquidityAllocation::query()->where('payout_id', $payout->id)->exists()) {
                $this->refuse('This transfer already has a submission claim. Reconcile it before any further action.');
            }
            $total = (int) PayoutLiquidityAllocation::query()->where('account_fingerprint', $fingerprint)->where('created_at', '>=', now()->subDay())->sum('amount');
            if ($payout->amount > $budget->remaining || $total + $payout->amount > (int) config('services.payout_schedules.daily_total_limit')) {
                $this->refuse('Available provider cash or the platform rolling limit prevents this transfer. No transfer was submitted.');
            }
            PayoutLiquidityAllocation::query()->create(['payout_id' => $payout->id, 'account_fingerprint' => $fingerprint,
                'provider_reference' => $payout->provider_reference, 'amount' => $payout->amount, 'created_at' => now()]);
            $budget->update(['remaining' => $budget->remaining - $payout->amount]);
        });
    }

    private function configured(): void
    {
        if (config('services.payout_schedules.liquidity_controls') !== true || config('services.payout_schedules.paused') === true
            || ! is_int(config('services.payout_schedules.cash_buffer')) || config('services.payout_schedules.cash_buffer') < 0
            || ! is_int(config('services.payout_schedules.daily_total_limit')) || config('services.payout_schedules.daily_total_limit') < 1) {
            $this->refuse('Platform payout controls are paused or await approved cash-buffer and transfer limits.');
        }
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['payout' => $message]);
    }
}
