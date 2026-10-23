<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Http\Controllers\Billing\WebhookController;
use App\Models\StoreOrder;
use App\Models\StorePaymentAttempt;
use App\Models\StorePaymentObservation;

final class StorePaymentNotifications
{
    public function __construct(private readonly StorePayments $payments, private readonly StorePaymentEvidence $evidence, private readonly StorePaymentConfirmer $confirmer, private readonly CommerceInventoryLocks $locks) {}

    public function reconcile(StorePaymentAttempt $attempt): StoreOrder
    {
        $data = $this->payments->verify($attempt);
        if (($data['status'] ?? null) === 'success') {
            return $this->confirmer->confirm($this->evidence->record($attempt, $data));
        }
        $attempt->getConnection()->transaction(function () use ($attempt, $data): void {
            $this->locks->order($attempt->order);
            $current = StorePaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $capture = StorePaymentObservation::query()->where('attempt_id', $current->id)->exists();
            if (! $capture && ($data['reference_reported'] ?? false) === true && ($data['reference'] ?? null) === $current->reference
                && ($data['status'] ?? null) === 'failed' && ! in_array($current->status, ['succeeded', 'review', 'captured'], true)) {
                $current->update(['status' => 'failed', 'last_verified_at' => now()]);
            } else {
                $current->update(['last_verified_at' => now()]);
            }
        });

        return $attempt->order->fresh();
    }

    /** Called only after the platform settlement signature matched.
     * @param  array<string, mixed>  $data
     */
    public function paystack(array $data, string $accountIdentity): bool
    {
        $metadata = WebhookController::metadata($data['metadata'] ?? null);
        if (($metadata['source'] ?? null) !== 'miconvener_store') {
            return false;
        }
        $reference = $data['reference'] ?? null;
        if (! is_string($reference)) {
            return true;
        }
        $attempt = StorePaymentAttempt::query()->where('reference', $reference)->first();
        if ($attempt === null || ! hash_equals($attempt->account_fingerprint, hash('sha256', $accountIdentity))) {
            return true;
        }
        $this->reconcile($attempt);

        return true;
    }
}
