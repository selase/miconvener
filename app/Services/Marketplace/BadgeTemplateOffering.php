<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Contracts\CommerceOffering;
use App\Models\BadgeTemplatePurchase;
use App\Models\StoreOrder;
use App\Models\StorePaymentAttempt;
use App\Models\StorePaymentObservation;
use App\Models\StoreRefund;
use Illuminate\Contracts\Session\Session;
use Illuminate\Validation\ValidationException;
use LogicException;

final class BadgeTemplateOffering implements CommerceOffering
{
    public function hasPurchase(StoreOrder $order): bool
    {
        return BadgeTemplatePurchase::query()->where('order_id', $order->id)->exists();
    }

    public function assertCheckout(StoreOrder $order, Session $session, ?string $acceptedHash): void
    {
        throw ValidationException::withMessages(['checkout' => 'Paid badge templates are awaiting payment readiness approval.']);
    }

    public function assertNewAttempt(StoreOrder $order): void
    {
        throw ValidationException::withMessages(['checkout' => 'Paid badge templates are awaiting payment readiness approval.']);
    }

    public function settleWithoutPayment(StoreOrder $order): void
    {
        throw new LogicException('A paid template requires verified payment.');
    }

    public function captureIssues(StoreOrder $order, StorePaymentObservation $capture): array
    {
        return ['badge_template_capture_unavailable'];
    }

    public function importsHistoricalCapture(StoreOrder $order): bool
    {
        return false;
    }

    public function onCaptureReview(StoreOrder $order): void
    {
        BadgeTemplatePurchase::query()->where('order_id', $order->id)->where('state', 'pending')->update(['state' => 'review']);
    }

    public function applyCapture(StoreOrder $order, StorePaymentAttempt $attempt, StorePaymentObservation $capture): void
    {
        throw new LogicException('Digital payment confirmation requires the licence issuer.');
    }

    public function expectedMetadata(StorePaymentAttempt $attempt): array
    {
        return ['source' => 'miconvener_badge_template', 'store_order_id' => $attempt->order_id, 'store_attempt_id' => $attempt->id, 'seller_id' => $attempt->tenant_id];
    }

    public function assertRefund(StoreOrder $order): void
    {
        throw ValidationException::withMessages(['refund' => 'Digital refunds require the platform licence refund workflow.']);
    }

    public function refundAllocation(StoreOrder $order, StorePaymentObservation $capture, bool $sale): CommerceRefundAllocation
    {
        if ($capture->amount_pesewas === null) {
            throw ValidationException::withMessages(['refund' => 'Verify the original capture amount first.']);
        }

        return new CommerceRefundAllocation(0, 0, $sale ? 0 : $capture->amount_pesewas, 'platform', false);
    }

    public function applyRefund(StoreRefund $refund, StoreOrder $order): void
    {
        throw new LogicException('Digital refunds require the licence refund workflow.');
    }

    public function receiptContext(StoreOrder $order): array
    {
        $purchase = BadgeTemplatePurchase::query()->with('event')->where('order_id', $order->id)->firstOrFail();

        return ['number' => $order->id, 'seller' => $order->seller_snapshot['name'], 'currency' => $order->currency,
            'total_pesewas' => $order->charged_pesewas,
            'lines' => [['title' => $purchase->terms_snapshot['design_name'] ?? 'Badge template licence', 'quantity' => 1, 'amount_pesewas' => $order->charged_pesewas]],
            'collection_terms' => 'Digital licence for '.$purchase->event->name.'. Unlimited badge reprints for this event.',
            'refund_terms' => $order->terms_snapshot['refund_terms'] ?? '', 'support_contact' => $order->terms_snapshot['support_contact'] ?? ''];
    }
}
