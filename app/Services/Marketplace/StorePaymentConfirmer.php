<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\StoreOrder;
use App\Models\StorePaymentAttempt;
use App\Models\StorePaymentObservation;
use App\Services\Finance\LedgerService;

final class StorePaymentConfirmer
{
    public function __construct(private readonly CommerceOrderLocks $locks, private readonly LedgerService $ledger, private readonly CommerceOfferingRegistry $offerings) {}

    public function confirm(StorePaymentObservation $capture): StoreOrder
    {
        return $capture->getConnection()->transaction(function () use ($capture): StoreOrder {
            $order = $this->locks->order($capture->order);
            $capture = StorePaymentObservation::query()->where('order_id', $order->id)->lockForUpdate()->findOrFail($capture->id);
            $attempt = StorePaymentAttempt::query()->where('order_id', $order->id)->lockForUpdate()->findOrFail($capture->attempt_id);
            if ($capture->processing_state === 'posted') {
                return $order;
            }
            if (StorePaymentObservation::query()->where('attempt_id', $attempt->id)->where('observation_version', '>', $capture->observation_version)->exists()) {
                $capture->update(['processing_state' => 'review', 'review_reason' => 'superseded_evidence']);

                return $order;
            }
            $issues = $capture->integrity_errors;
            if ($capture->review_reason === 'verification_changed') {
                $issues[] = 'verification_changed';
            }
            if ($order->payment_state === 'paid') {
                $issues[] = 'additional_capture';
            }
            if ($order->accepted_at === null) {
                $issues[] = 'quote_not_accepted';
            }
            $offering = $this->offerings->for($order);
            $issues = [...$issues, ...$offering->captureIssues($order, $capture)];
            if ($issues !== []) {
                $txn = $capture->ledger_transaction_id === null ? $this->ledger->recordStoreCaptureReview($capture) : null;
                $capture->update(['processing_state' => 'review', 'review_reason' => $issues[0], 'ledger_transaction_id' => $capture->ledger_transaction_id ?? $txn?->id]);
                $attempt->update(['status' => 'review', 'review_reason' => $issues[0]]);
                if ($order->payment_state !== 'paid') {
                    $order->update(['state' => 'review', 'payment_state' => 'captured_review', 'ledger_state' => 'review', 'review_reason' => $issues[0]]);
                    $offering->onCaptureReview($order);
                } else {
                    $order->update(['review_reason' => $issues[0]]);
                }

                return $order;
            }
            $offering->applyCapture($order, $attempt, $capture);

            return $order;
        });
    }
}
