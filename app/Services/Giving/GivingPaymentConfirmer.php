<?php

declare(strict_types=1);

namespace App\Services\Giving;

use App\Http\Controllers\Billing\WebhookController;
use App\Http\Controllers\Tenant\Commerce\WebhookController as MerchantWebhook;
use App\Models\EventContribution;
use App\Models\GivingFund;
use App\Models\GivingGift;
use App\Models\GivingPaymentAttempt;
use App\Models\Tenant;
use App\Services\Finance\LedgerService;
use App\Services\Operations\OperationalSignals;
use Carbon\CarbonImmutable;

/** Only called with verified account data from a gateway lookup or signed webhook. */
final class GivingPaymentConfirmer
{
    public function __construct(private readonly GivingSources $sources, private readonly LedgerService $ledger, private readonly GivingProgress $progress) {}

    /** @param array<string, mixed> $verifiedData */
    public function confirm(string $reference, array $verifiedData): ?GivingPaymentAttempt
    {
        $candidate = GivingPaymentAttempt::withoutGlobalScopes()->where('reference', $reference)->first();
        if ($candidate === null) {
            return null;
        }
        if (! in_array($verifiedData['status'] ?? '', ['success', 'succeeded'], true)) {
            return null;
        }

        return $candidate->getConnection()->transaction(function () use ($candidate, $verifiedData): ?GivingPaymentAttempt {
            $fund = GivingFund::withoutGlobalScopes()->where('tenant_id', $candidate->tenant_id)->lockForUpdate()->findOrFail($candidate->fund_id);
            $attempt = GivingPaymentAttempt::withoutGlobalScopes()->where('tenant_id', $candidate->tenant_id)->lockForUpdate()->findOrFail($candidate->id);
            $metadata = WebhookController::metadata($verifiedData['metadata'] ?? null);
            if (! $this->matches($attempt, $metadata, $verifiedData)) {
                $this->quarantine($attempt, 'verification_mismatch');

                return null;
            }
            if ($attempt->state === 'confirmed') {
                return $attempt;
            }
            $fees = $verifiedData['fees'] ?? null;
            $received = $verifiedData['commission_received'] ?? 0;
            if (! is_int($fees) || $fees < 0 || $fees + $attempt->commission_amount > $attempt->amount || ! is_int($received) || $received < 0 || $received > $attempt->commission_amount) {
                $this->quarantine($attempt, 'unpostable_verified_amounts');

                return null;
            }
            $type = $attempt->gift_id === null ? 'event_contribution' : 'gift';
            $source = $this->sources->lock($attempt->tenant, $type, $attempt->gift_id ?? $attempt->event_contribution_id);
            if ($source->fund->id !== $fund->id || $source->principal !== $attempt->amount || $source->currency !== $attempt->currency || ! in_array($source->model->status, ['pending', EventContribution::STATUS_PENDING_PAYMENT], true)) {
                $this->quarantine($attempt, 'source_mismatch');

                return null;
            }
            $providerReference = $verifiedData['provider_reference'];
            if (GivingPaymentAttempt::withoutGlobalScopes()->where('provider', $attempt->provider)->where('account_identity', $attempt->account_identity)->where('provider_reference', $providerReference)->whereKeyNot($attempt->id)->exists()) {
                $this->quarantine($attempt, 'duplicate_provider_reference');

                return null;
            }
            $settlement = ['received' => 0, 'credit' => 0];
            if ($attempt->settlement_mode !== Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT) {
                $snapshot = $attempt->commission_settlement;
                $snapshot['commission_collected'] = $received;
                $settlement = MerchantWebhook::commissionSettlement($attempt->tenant, $attempt->currency, $attempt->provider, $snapshot, $attempt->commission_amount, $received);
            }
            $this->ledger->recordGivingPayment($attempt->tenant, $source, $attempt, ['gateway_fee' => $fees, 'commission_received' => $settlement['received'], 'credit_used' => $settlement['credit']]);
            $paidAt = ! empty($verifiedData['paid_at']) ? CarbonImmutable::parse($verifiedData['paid_at'])->utc() : CarbonImmutable::now();
            $common = ['status' => 'completed', 'paid_at' => $paidAt, 'gateway_fee_amount' => $fees, 'net_amount' => $attempt->amount - $fees - $attempt->commission_amount];
            $source->model->update($source->model instanceof GivingGift
                ? [...$common, 'commission_amount' => $attempt->commission_amount]
                : [...$common, 'platform_fee_amount' => $attempt->commission_amount, 'payment_reference' => $providerReference,
                    'paystack_reference' => $attempt->provider === 'paystack' ? $providerReference : null, 'provider' => $attempt->provider]);
            $attempt->update(['commission_settlement' => [...$attempt->commission_settlement, 'actual_received' => $settlement['received'], 'actual_credit_used' => $settlement['credit']], 'state' => 'confirmed', 'confirmed_at' => now(), 'provider_reference' => $providerReference, 'review_reason' => null]);
            $this->progress->closeIfTargetReached($fund);

            return $attempt;
        });
    }

    public function quarantine(GivingPaymentAttempt $attempt, string $reason): void
    {
        GivingPaymentAttempt::withoutGlobalScopes()->whereKey($attempt->id)->where('state', '!=', 'confirmed')->update(['state' => 'needs_review', 'review_reason' => $reason]);
        $attempt->refresh();
        app(OperationalSignals::class)->recordWebhookFailure('processing', 'Giving attempt '.$attempt->id.': '.$reason);
    }

    /** @param array<string, mixed> $metadata
     * @param  array<string, mixed>  $data
     */
    private function matches(GivingPaymentAttempt $attempt, array $metadata, array $data): bool
    {
        $expected = ['type' => 'community_gift', 'giving_attempt_id' => $attempt->id, 'tenant_id' => $attempt->tenant_id,
            'fund_id' => $attempt->fund_id, 'local_reference' => $attempt->reference,
            'source_type' => $attempt->gift_id === null ? 'event_contribution' : 'gift', 'source_id' => $attempt->gift_id ?? $attempt->event_contribution_id];
        foreach ($expected as $key => $value) {
            if (($metadata[$key] ?? null) !== $value) {
                return false;
            }
        }
        if (($data['provider'] ?? null) !== $attempt->provider || ($data['account_identity'] ?? null) !== $attempt->account_identity || ($data['amount'] ?? null) !== $attempt->amount || mb_strtoupper((string) ($data['currency'] ?? '')) !== $attempt->currency || ! is_string($data['provider_reference'] ?? null) || $data['provider_reference'] === '') {
            return false;
        }
        if ($attempt->provider === 'paystack') {
            return ($data['reference'] ?? null) === $attempt->reference && $data['provider_reference'] === $attempt->reference;
        }

        return ($data['session_id'] ?? null) === $attempt->provider_session_id
            && str_starts_with($data['provider_reference'], 'pi_')
            && ($attempt->provider_reference === null || $data['provider_reference'] === $attempt->provider_reference);
    }
}
