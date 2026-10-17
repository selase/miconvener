<?php

declare(strict_types=1);

namespace App\Services\Giving;

use App\Enum\LedgerCategoryEnum;
use App\Models\GivingAdjustment;
use App\Models\GivingFund;
use App\Models\GivingGift;
use App\Models\GivingPaymentAttempt;
use App\Models\User;
use App\Services\Finance\CommercialTerms;
use App\Services\Finance\LedgerService;
use App\Services\Operations\OperationalSignals;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class GivingRefunds
{
    public function __construct(private readonly GivingSources $sources, private readonly GivingBalance $balance, private readonly GivingGateways $gateways, private readonly LedgerService $ledger, private readonly CommercialTerms $terms) {}

    /** @return array<string, int|string|bool> */
    public function preview(GivingSource $source, User $actor, int $principal): array
    {
        $this->authorize($source, $actor);
        $attempt = $this->attempt($source);
        $previous = $this->adjustments($source)->get();
        $confirmed = $previous->where('status', 'confirmed');
        $refunded = (int) $confirmed->sum('principal');
        if ($principal <= 0 || $principal > $source->principal - $refunded || $previous->whereIn('status', ['requested', 'processing', 'unknown'])->isNotEmpty()) {
            throw ValidationException::withMessages(['principal' => 'Choose an amount within the remaining contribution and resolve any pending refund first.']);
        }
        $reversal = $this->terms->refundedCommission($source->principal, $attempt->commission_amount, $refunded + $principal) - (int) $confirmed->sum('commission_reversal');
        $impact = $attempt->settlement_mode === \App\Models\Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT ? $principal - $reversal : 0;
        $available = $this->balance->available($source->fund->tenant, $source->currency);

        return ['principal' => $principal, 'currency' => $source->currency, 'remaining_principal' => $source->principal - $refunded,
            'commission_reversal' => $reversal, 'gateway_fee_credit' => 0, 'original_gateway_fee' => $source->model->gateway_fee_amount,
            'payable_impact' => $impact, 'would_leave_amount_owed' => $impact > $available, 'amount_owed' => max(0, $impact - $available)];
    }

    public function request(GivingSource $source, User $actor, int $principal, string $reason, string $operationKey): GivingAdjustment
    {
        $this->authorize($source, $actor);
        if (! Str::isUuid($operationKey) || $principal <= 0 || mb_trim($reason) === '') {
            throw ValidationException::withMessages(['principal' => 'Provide an amount, a reason and a valid operation identifier.']);
        }
        $this->attempt($source);
        $this->ledger->ensureAccountsExist($source->fund->tenant, null, $source->currency, LedgerCategoryEnum::Giving);
        [$adjustment, $attempt, $isNew] = $source->fund->getConnection()->transaction(function () use ($source, $actor, $principal, $reason, $operationKey): array {
            GivingFund::withoutGlobalScopes()->where('tenant_id', $source->fund->tenant_id)->lockForUpdate()->findOrFail($source->fund->id);
            $source = $this->sources->lock($source->fund->tenant, $source->type, $source->id);
            $this->balance->lockPayableAccount($source->fund->tenant, $source->currency);
            $attempt = $this->attempt($source);
            $old = GivingAdjustment::withoutGlobalScopes()->where('tenant_id', $source->fund->tenant_id)->where('operation_key', $operationKey)->first();
            if ($old !== null) {
                if ($old->gift_id !== $source->columns()['gift_id'] || $old->event_contribution_id !== $source->columns()['event_contribution_id'] || $old->principal !== $principal || $old->reason !== mb_trim($reason)) {
                    throw ValidationException::withMessages(['operation_key' => 'This operation already records a different refund.']);
                }

                return [$old, $attempt, false];
            }
            $previous = $this->adjustments($source)->get();
            $confirmed = $previous->where('status', 'confirmed');
            $refunded = (int) $confirmed->sum('principal');
            if ($previous->whereIn('status', ['requested', 'processing', 'unknown'])->isNotEmpty() || $principal > $source->principal - $refunded) {
                throw ValidationException::withMessages(['principal' => 'A refund is already pending or this exceeds the remaining contribution.']);
            }
            $reversal = $this->terms->refundedCommission($source->principal, $attempt->commission_amount, $refunded + $principal) - (int) $confirmed->sum('commission_reversal');
            if ($attempt->settlement_mode === \App\Models\Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT && $this->balance->inFlight($source->fund->tenant, $source->currency) > 0 && $principal - $reversal > $this->balance->available($source->fund->tenant, $source->currency)) {
                throw ValidationException::withMessages(['principal' => 'A payout is in flight. Resolve it before refunding money already committed to that payout.']);
            }
            $adjustment = GivingAdjustment::withoutGlobalScopes()->create([
                ...$source->columns(), 'tenant_id' => $source->fund->tenant_id, 'fund_id' => $source->fund->id,
                'kind' => 'refund', 'status' => 'requested', 'principal' => $principal, 'commission_reversal' => $reversal,
                'reason' => mb_trim($reason), 'actor_id' => $actor->id, 'operation_key' => $operationKey,
            ]);

            return [$adjustment, $attempt, true];
        });
        if (! $isNew) {
            return $adjustment;
        }
        try {
            $result = $this->gateways->requestRefund($attempt, $adjustment);
            $adjustment->getConnection()->transaction(function () use ($adjustment, $attempt, $result): void {
                $locked = GivingAdjustment::withoutGlobalScopes()->lockForUpdate()->findOrFail($adjustment->id);
                if ($locked->status !== 'confirmed') {
                    $locked->update(['provider_refund_reference' => $result['reference'] !== '' ? $result['reference'] : null,
                        'provider_refund_key' => $result['reference'] !== '' ? hash('sha256', $attempt->provider.'|'.$attempt->account_identity.'|'.$result['reference']) : null, 'status' => $result['reference'] === '' ? 'unknown' : 'processing']);
                }
            });
        } catch (Throwable) {
            GivingAdjustment::withoutGlobalScopes()->whereKey($adjustment->id)->where('status', '!=', 'confirmed')->update(['status' => 'unknown']);
            app(OperationalSignals::class)->recordWebhookFailure('processing', 'Giving refund '.$adjustment->id.' has an unknown provider outcome.');
        }

        return $adjustment->refresh();
    }

    /** @param array<string, mixed> $verifiedOutcome */
    public function confirm(GivingAdjustment $adjustment, array $verifiedOutcome): void
    {
        $adjustment->getConnection()->transaction(function () use ($adjustment, $verifiedOutcome): void {
            $fund = GivingFund::withoutGlobalScopes()->where('tenant_id', $adjustment->tenant_id)->lockForUpdate()->findOrFail($adjustment->fund_id);
            $source = $this->sources->lock($fund->tenant, $adjustment->gift_id === null ? 'event_contribution' : 'gift', $adjustment->gift_id ?? $adjustment->event_contribution_id);
            $this->balance->lockPayableAccount($fund->tenant, $source->currency);
            $adjustment = GivingAdjustment::withoutGlobalScopes()->lockForUpdate()->findOrFail($adjustment->id);
            if ($adjustment->status === 'confirmed') {
                return;
            }
            $attempt = $this->attempt($source);
            if (($verifiedOutcome['account_identity'] ?? null) !== $attempt->account_identity || ($verifiedOutcome['provider'] ?? null) !== $attempt->provider || ($verifiedOutcome['provider_refund_reference'] ?? null) !== $adjustment->provider_refund_reference || $adjustment->provider_refund_reference === null || ($verifiedOutcome['payment_reference'] ?? null) !== $attempt->provider_reference || ($verifiedOutcome['amount'] ?? null) !== $adjustment->principal || mb_strtoupper((string) ($verifiedOutcome['currency'] ?? '')) !== $source->currency) {
                app(OperationalSignals::class)->recordWebhookFailure('processing', 'Giving refund '.$adjustment->id.' verification mismatch.');

                return;
            }
            if (($verifiedOutcome['status'] ?? '') === 'failed') {
                $adjustment->update(['status' => 'failed']);

                return;
            }
            if (! in_array($verifiedOutcome['status'] ?? '', ['processed', 'succeeded'], true)) {
                return;
            }
            $confirmed = $this->adjustments($source)->where('status', 'confirmed')->get();
            $cumulative = (int) $confirmed->sum('principal') + $adjustment->principal;
            if ($cumulative > $source->principal) {
                throw ValidationException::withMessages(['principal' => 'This refund exceeds the original contribution.']);
            }
            $credit = $verifiedOutcome['gateway_credit'] ?? 0;
            if (! is_int($credit) || $credit < 0 || $credit + $confirmed->sum('gateway_credit') > $source->model->gateway_fee_amount) {
                throw ValidationException::withMessages(['gateway_credit' => 'Processing-fee credit exceeds the recorded original fee.']);
            }
            $reversal = $this->terms->refundedCommission($source->principal, $attempt->commission_amount, $cumulative) - (int) $confirmed->sum('commission_reversal');
            $returned = $verifiedOutcome['commission_returned'] ?? 0;
            if (isset($verifiedOutcome['commission_returned_cumulative'])) {
                $returned = $verifiedOutcome['commission_returned_cumulative'] - (int) $confirmed->sum('commission_returned');
            }
            if (! is_int($returned) || $returned < 0 || $returned + $confirmed->sum('commission_returned') > (int) ($attempt->commission_settlement['actual_received'] ?? 0)) {
                app(OperationalSignals::class)->recordWebhookFailure('processing', 'Giving refund '.$adjustment->id.' commission credit needs review.');

                return;
            }
            $creditRestored = $this->terms->refundedCommission($source->principal, (int) ($attempt->commission_settlement['actual_credit_used'] ?? 0), $cumulative) - (int) $confirmed->sum('credit_restored');
            $creditRestored = max(0, $creditRestored);
            $adjustment->fill(['commission_reversal' => $reversal, 'gateway_credit' => $credit, 'commission_returned' => $returned, 'credit_restored' => $creditRestored]);
            $this->ledger->recordGivingRefund($fund->tenant, $source, $adjustment);
            $adjustment->fill(['status' => 'confirmed', 'confirmed_at' => now()])->save();
            if ($this->balance->payable($fund->tenant, $source->currency) < 0) {
                $adjustment->getConnection()->afterCommit(fn () => app(OperationalSignals::class)->recordWebhookFailure('processing', 'Giving refund '.$adjustment->id.': negative payable; further payouts are held for operational review.'));
            }
            if ($cumulative === $source->principal) {
                $source->model->update(['status' => 'refunded']);
            }
        });
    }

    public function reconcile(GivingAdjustment $adjustment, ?string $candidateReference = null): void
    {
        $source = $this->sources->find($adjustment->fund->tenant, $adjustment->gift_id === null ? 'event_contribution' : 'gift', $adjustment->gift_id ?? $adjustment->event_contribution_id);
        $attempt = $this->attempt($source);
        $probe = clone $adjustment;
        if ($candidateReference !== null) {
            if ($adjustment->provider_refund_reference !== null && $adjustment->provider_refund_reference !== $candidateReference) {
                throw ValidationException::withMessages(['provider_refund_reference' => 'The original refund reference cannot be replaced.']);
            }
            $probe->provider_refund_reference = $candidateReference;
        }
        $data = $this->gateways->verifyRefund($attempt, $probe);
        if ($adjustment->provider_refund_reference === null && $candidateReference !== null) {
            if (($data['amount'] ?? null) !== $adjustment->principal || ($data['currency'] ?? '') !== $source->currency || ($data['status'] ?? 'unknown') === 'unknown') {
                throw ValidationException::withMessages(['provider_refund_reference' => 'This refund does not match the original operation on its original account.']);
            }
            $adjustment->getConnection()->transaction(function () use ($adjustment, $candidateReference, $attempt): void {
                $locked = GivingAdjustment::withoutGlobalScopes()->lockForUpdate()->findOrFail($adjustment->id);
                if ($locked->provider_refund_reference === null && $locked->status !== 'confirmed') {
                    $locked->update(['provider_refund_reference' => $candidateReference, 'provider_refund_key' => hash('sha256', $attempt->provider.'|'.$attempt->account_identity.'|'.$candidateReference)]);
                }
            });
            $adjustment->refresh();
        }
        $this->confirm($adjustment, $data);
    }

    /**
     * A notification prompts a lookup of our own refund ID, not an amount-based match.
     *
     * @param  array<string, mixed>  $data
     */
    public function handlePaystackNotification(array $data, string $accountIdentity): bool
    {
        $reference = $data['transaction_reference'] ?? null;
        if (! is_string($reference)) {
            return false;
        }
        $attempt = GivingPaymentAttempt::withoutGlobalScopes()->where('provider', 'paystack')->where('account_identity', $accountIdentity)->where('provider_reference', $reference)->where('state', 'confirmed')->first();
        if ($attempt === null) {
            return false;
        }
        $source = $this->sources->find($attempt->tenant, $attempt->gift_id === null ? 'event_contribution' : 'gift', $attempt->gift_id ?? $attempt->event_contribution_id);
        $adjustment = $this->adjustments($source)->whereIn('status', ['requested', 'processing', 'unknown'])->first();
        if ($adjustment !== null && $adjustment->provider_refund_reference !== null) {
            $this->reconcile($adjustment);
        }

        return true;
    }

    public function handleStripeNotification(object $data, string $accountIdentity): void
    {
        $reference = $data->payment_intent ?? null;
        if (! is_string($reference)) {
            return;
        }
        $attempt = GivingPaymentAttempt::withoutGlobalScopes()->where('provider', 'stripe')->where('account_identity', $accountIdentity)->where('provider_reference', $reference)->where('state', 'confirmed')->first();
        if ($attempt === null) {
            return;
        }
        $source = $this->sources->find($attempt->tenant, $attempt->gift_id === null ? 'event_contribution' : 'gift', $attempt->gift_id ?? $attempt->event_contribution_id);
        foreach ($this->adjustments($source)->whereIn('status', ['requested', 'processing', 'unknown'])->whereNotNull('provider_refund_reference')->get() as $adjustment) {
            $this->reconcile($adjustment);
        }
    }

    private function attempt(GivingSource $source): GivingPaymentAttempt
    {
        $attempt = GivingPaymentAttempt::withoutGlobalScopes()->where('tenant_id', $source->fund->tenant_id)->where('fund_id', $source->fund->id)->where($source->type === 'gift' ? 'gift_id' : 'event_contribution_id', $source->id)->where('state', 'confirmed')->first();
        if ($attempt === null || ($source->model instanceof GivingGift && $source->model->source !== 'online')) {
            throw ValidationException::withMessages(['principal' => 'Use a cash correction for recorded cash; only confirmed Giving checkouts can be refunded here.']);
        }

        return $attempt;
    }

    /** @return \Illuminate\Database\Eloquent\Builder<GivingAdjustment> */
    private function adjustments(GivingSource $source): \Illuminate\Database\Eloquent\Builder
    {
        return GivingAdjustment::withoutGlobalScopes()->where('tenant_id', $source->fund->tenant_id)->where($source->type === 'gift' ? 'gift_id' : 'event_contribution_id', $source->id)->where('kind', 'refund');
    }

    private function authorize(GivingSource $source, User $actor): void
    {
        abort_unless($actor->tenant_id === $source->fund->tenant_id || $source->fund->tenant->users()->where('users.id', $actor->id)->exists(), 403);
        Gate::forUser($actor)->authorize('read finance');
        Gate::forUser($actor)->authorize('process refunds');
    }
}
