<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\StoreOrder;
use App\Models\StorePaymentAttempt;
use App\Models\StorePaymentObservation;
use App\Models\StoreRefund;
use Illuminate\Contracts\Session\Session;

/**
 * What differs between kinds of order that share one payment, ledger and refund
 * pipeline. The shared services own state, evidence and locking; an offering owns
 * only the rules of its own kind (stock for goods, calendar and contract for a
 * venue). Every hook runs inside the order lock the shared service already holds.
 */
interface CommerceOffering
{
    /**
     * Refuse a checkout the order's kind does not allow: rollout, terms, buyer commitment.
     */
    public function assertCheckout(StoreOrder $order, Session $session, ?string $acceptedHash): void;

    /**
     * Refuse starting a new provider attempt when the thing being sold is no longer available.
     */
    public function assertNewAttempt(StoreOrder $order): void;

    /**
     * Complete an order that needs no payment.
     */
    public function settleWithoutPayment(StoreOrder $order): void;

    /**
     * Why a received capture cannot be applied to this order, as stable review reasons.
     *
     * @return list<string>
     */
    public function captureIssues(StoreOrder $order, StorePaymentObservation $capture): array;

    /**
     * Whether this order carries money captured before it existed, so its capture
     * is judged against the original chronology rather than this order's own.
     */
    public function importsHistoricalCapture(StoreOrder $order): bool;

    /**
     * Release whatever the order held when its capture goes to review.
     */
    public function onCaptureReview(StoreOrder $order): void;

    /**
     * Post a clean capture and apply its effect on the order, in the same transaction.
     */
    public function applyCapture(StoreOrder $order, StorePaymentAttempt $attempt, StorePaymentObservation $capture): void;

    /**
     * The metadata the provider must echo back for an attempt of this order.
     *
     * @return array<string, string>
     */
    public function expectedMetadata(StorePaymentAttempt $attempt): array;

    /**
     * Refuse a refund this kind of order does not support yet.
     */
    public function assertRefund(StoreOrder $order): void;

    /**
     * Apply a confirmed refund's effect on the order.
     */
    public function applyRefund(StoreRefund $refund, StoreOrder $order): void;

    /**
     * The public-safe fields an immutable receipt records.
     *
     * @return array<string, mixed>
     */
    public function receiptContext(StoreOrder $order): array;
}
