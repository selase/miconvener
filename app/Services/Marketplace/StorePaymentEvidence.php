<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Http\Controllers\Billing\WebhookController;
use App\Models\StorePaymentAttempt;
use App\Models\StorePaymentObservation;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Receives only server-side original-account transaction verification. */
final class StorePaymentEvidence
{
    public function __construct(private readonly CommerceOrderLocks $locks, private readonly CommerceOfferingRegistry $offerings) {}

    /** @param array<string, mixed> $verified */
    public function record(StorePaymentAttempt $attempt, array $verified): StorePaymentObservation
    {
        if (($verified['status'] ?? null) !== 'success') {
            throw ValidationException::withMessages(['payment' => 'A confirmed capture is required to record received money.']);
        }
        $attempt = StorePaymentAttempt::query()->with('order')->findOrFail($attempt->id);
        $errors = [];
        $amount = $this->minor($verified['amount'] ?? null);
        $fee = $this->minor($verified['fees'] ?? null);
        $currency = is_string($verified['currency'] ?? null) && mb_strlen($verified['currency']) === 3 ? mb_strtoupper($verified['currency']) : null;
        $account = ($verified['provider'] ?? null) === $attempt->provider && is_string($verified['account_fingerprint'] ?? null) && hash_equals($attempt->account_fingerprint, $verified['account_fingerprint']);
        $reference = ($verified['reference_reported'] ?? false) === true && ($verified['reference'] ?? null) === $attempt->reference;
        if (! $account) {
            $errors[] = 'account_mismatch';
        }
        if (! $reference) {
            $errors[] = 'reference_mismatch';
        }
        if ($amount !== $attempt->amount_pesewas) {
            $errors[] = 'amount_mismatch';
        }
        if ($currency !== $attempt->currency) {
            $errors[] = 'currency_mismatch';
        }
        if ($fee === null || $amount === null || $fee + $attempt->order->platform_fee_pesewas >= $amount) {
            $errors[] = 'gateway_fee_unpostable';
        }
        $metadata = WebhookController::metadata($verified['metadata'] ?? null);
        $offering = $this->offerings->for($attempt->order);
        $imported = $offering->importsHistoricalCapture($attempt->order);
        $expected = $offering->expectedMetadata($attempt);
        foreach ($expected as $key => $value) {
            if (($metadata[$key] ?? null) !== $value) {
                $errors[] = 'metadata_mismatch';
                break;
            }
        }
        $paidAt = null;
        try {
            if (is_string($verified['paid_at'] ?? null) && $verified['paid_at'] !== '') {
                $paidAt = CarbonImmutable::parse($verified['paid_at'])->utc();
            }
        } catch (Throwable) {
            $paidAt = null;
        }
        if ($paidAt === null || $paidAt->greaterThan(now()->addSeconds(30)) || (! $imported && $paidAt->lessThan($attempt->created_at->copy()->subSeconds(1)))) {
            $errors[] = 'capture_time_unknown';
        }
        if (! $imported && $paidAt !== null && $paidAt->greaterThanOrEqualTo($attempt->order->expires_at)) {
            $errors[] = 'capture_after_expiry';
        }
        $transactionId = $verified['transaction_id'] ?? null;
        $transactionId = (is_int($transactionId) && $transactionId > 0) || (is_string($transactionId) && preg_match('/^[0-9]{1,30}$/D', $transactionId)) ? (string) $transactionId : null;
        $snapshot = ['attempt_id' => $attempt->id, 'order_id' => $attempt->order_id, 'account_fingerprint' => $attempt->account_fingerprint,
            'reference' => $attempt->reference, 'account_matches' => $account, 'reference_matches' => $reference,
            'amount_pesewas' => $amount, 'gateway_fee_pesewas' => $fee, 'currency' => $currency, 'paid_at' => $paidAt?->toIso8601String(),
            'provider_transaction_id' => $transactionId, 'metadata_snapshot' => $expected, 'integrity_errors' => $errors];
        $hash = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));

        return $attempt->getConnection()->transaction(function () use ($attempt, $snapshot, $hash): StorePaymentObservation {
            $order = $this->locks->order($attempt->order);
            $same = StorePaymentObservation::query()->where('attempt_id', $attempt->id)->where('payload_hash', $hash)->first();
            if ($same !== null) {
                return $same;
            }
            $prior = StorePaymentObservation::query()->where('attempt_id', $attempt->id)->orderByDesc('observation_version')->first();
            $posted = StorePaymentObservation::query()->where('attempt_id', $attempt->id)->whereNotNull('ledger_transaction_id')->orderByDesc('observation_version')->first();
            $capture = StorePaymentObservation::query()->create([...$snapshot, 'payload_hash' => $hash, 'verified_at' => now(),
                'observation_version' => ($prior->observation_version ?? 0) + 1, 'supersedes_id' => $prior?->id,
                'processing_state' => $posted === null ? 'pending' : 'review', 'review_reason' => $posted === null ? null : 'verification_changed',
                'ledger_transaction_id' => $posted?->ledger_transaction_id]);
            if ($posted !== null) {
                $order->update(['review_reason' => 'verification_changed', 'ledger_state' => 'review']);
            }
            StorePaymentAttempt::query()->whereKey($attempt->id)->update(['status' => 'captured']);
            if ($order->payment_state !== 'paid') {
                $order->update(['state' => 'review', 'payment_state' => 'captured_review', 'review_reason' => 'capture_processing']);
            }

            return $capture;
        });
    }

    private function minor(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 && $value <= 9007199254740991 ? $value : null;
    }
}
