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
        $purchase = BadgeTemplatePurchase::query()->with('event')->where('order_id', $order->id)->firstOrFail();
        $actor = auth()->user();
        abort_if($actor === null, 403);
        app(\App\Services\Badges\BadgeTemplatePurchases::class)->authorize($actor, $purchase->event);
        app(\App\Services\Badges\BadgeTemplateReadiness::class)->agreement(\App\Models\Tenant::query()->whereKey($purchase->buyer_tenant_id)->firstOrFail());
        $family = $purchase->version->design;
        if ($family->withdrawn_at !== null || $family->scope !== 'platform' || $family->price_pesewas !== 5000) {
            throw ValidationException::withMessages(['checkout' => 'This template is no longer available for a new payment.']);
        }
        if ($acceptedHash === null || ! hash_equals($order->terms_hash, $acceptedHash) || $purchase->state !== 'pending') {
            throw ValidationException::withMessages(['checkout' => 'Review and accept the current event licence terms.']);
        }
    }

    public function assertNewAttempt(StoreOrder $order): void
    {
        if ($order->charged_pesewas !== 5000 || $order->currency !== 'GHS' || $order->platform_fee_pesewas !== 0) {
            throw ValidationException::withMessages(['checkout' => 'The paid template price is unavailable.']);
        }
    }

    public function settleWithoutPayment(StoreOrder $order): void
    {
        throw new LogicException('A paid template requires verified payment.');
    }

    public function captureIssues(StoreOrder $order, StorePaymentObservation $capture): array
    {
        $purchase = BadgeTemplatePurchase::query()->with(['event', 'version.design', 'licence'])->where('order_id', $order->id)->firstOrFail();
        if ($purchase->event === null || $purchase->event->tenant_id !== $purchase->buyer_tenant_id || $purchase->version->design->withdrawn_at !== null) {
            return ['template_target_unavailable'];
        }
        if ($purchase->state !== 'pending' || ! in_array($purchase->licence->state, ['pending', 'refunded', 'revoked'], true)) {
            return ['template_licence_unavailable'];
        }
        if ($capture->paid_at === null || $capture->paid_at->greaterThanOrEqualTo($order->expires_at)) {
            return ['capture_after_expiry'];
        }

        return [];
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
        $transaction = app(\App\Services\Finance\LedgerService::class)->recordBadgeTemplateSale($order, $attempt, $capture);
        $capture->update(['ledger_transaction_id' => $transaction->id, 'processing_state' => 'posted', 'review_reason' => null]);
        $order->update(['state' => 'confirmed', 'payment_state' => 'paid', 'ledger_state' => 'posted', 'paid_at' => $capture->paid_at, 'review_reason' => null]);
        $attempt->update(['status' => 'succeeded', 'last_verified_at' => now()]);
        app(\App\Services\Badges\BadgeTemplateLicences::class)->grant(BadgeTemplatePurchase::query()->where('order_id', $order->id)->firstOrFail(), $capture);
        app(StoreReceipts::class)->capture($order);
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
            'digital_licence' => ['event' => $purchase->event->name, 'family_id' => $purchase->family_id, 'version_id' => $purchase->version_id, 'terms' => $purchase->terms_snapshot['licence'] ?? 'Unlimited badge reprints for this event.'],
            'refund_terms' => $order->terms_snapshot['refund_terms'] ?? '', 'support_contact' => $order->terms_snapshot['support_contact'] ?? ''];
    }
}
