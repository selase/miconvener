<?php

declare(strict_types=1);

namespace App\Services\Badges;

use App\Enum\LedgerCategoryEnum;
use App\Models\BadgeTemplatePurchase;
use App\Models\LedgerAccount;
use App\Models\StorePaymentObservation;
use App\Models\StoreRefund;
use App\Models\Tenant;
use Illuminate\Validation\ValidationException;

final class BadgeTemplateReserve
{
    public function available(Tenant $seller, ?string $excludeCapture = null, ?string $excludeOrder = null): int
    {
        $cash = LedgerAccount::withoutGlobalScopes()->where('tenant_id', $seller->id)->where('category', LedgerCategoryEnum::Commerce)
            ->whereNull('event_id')->where('currency', 'GHS')->where('code', LedgerAccount::CODE_TEMPLATE_REFUND_RESERVE)->first()?->currentBalance() ?? 0;
        $captures = [];
        $orderMinimums = [];
        $excludedAttempt = $excludeCapture === null ? null : StorePaymentObservation::query()->whereKey($excludeCapture)->value('attempt_id');
        $confirmed = StoreRefund::query()->where('tenant_id', $seller->id)->where('status', 'confirmed')->whereNotNull('attempt_id')->pluck('amount_pesewas', 'attempt_id')->all();
        $hold = function (?string $attemptId, string $orderId, int $amount) use (&$captures, $excludedAttempt, $excludeOrder): void {
            if ($attemptId === null || $attemptId === $excludedAttempt || $orderId === $excludeOrder) {
                return;
            }
            $captures[$attemptId] = ['order' => $orderId, 'amount' => max($captures[$attemptId]['amount'] ?? 0, $amount)];
        };
        $pendingRefunds = StoreRefund::query()->where('tenant_id', $seller->id)->where('status', '<>', 'confirmed')
            ->whereHas('order', fn ($query) => $query->where('offering_type', 'badge_template'))->get();
        foreach ($pendingRefunds as $refund) {
            $hold($refund->attempt_id, $refund->order_id, $refund->amount_pesewas);
        }
        $held = StorePaymentObservation::query()->whereHas('order', fn ($query) => $query->where('tenant_id', $seller->id)->where('offering_type', 'badge_template'))
            ->whereIn('processing_state', ['pending', 'review'])->where('account_matches', true)->where('reference_matches', true)->where('currency', 'GHS')->get();
        foreach ($held as $capture) {
            if ($capture->amount_pesewas !== null) {
                $hold($capture->attempt_id, $capture->order_id, $capture->amount_pesewas);
            }
        }
        $purchases = BadgeTemplatePurchase::query()->with(['order.attempts', 'licence'])
            ->whereHas('order', fn ($query) => $query->where('tenant_id', $seller->id)->where('offering_type', 'badge_template'))
            ->where(fn ($query) => $query->whereIn('state', ['pending', 'review'])->orWhere(fn ($paid) => $paid->where('state', 'paid')->whereHas('order', fn ($order) => $order->where('paid_at', '>=', now()->subDays(8)))))->get();
        $observations = StorePaymentObservation::query()->whereIn('order_id', $purchases->pluck('order_id'))->get()->groupBy('order_id');
        $used = array_fill_keys(\App\Models\BadgeTemplateUse::query()->whereIn('purchase_id', $purchases->pluck('id'))->pluck('purchase_id')->all(), true);
        foreach ($purchases as $purchase) {
            $order = $purchase->order;
            if ($order->id === $excludeOrder) {
                continue;
            }
            if ($purchase->state === 'paid' && $purchase->licence->state === 'active' && $purchase->licence->current_purchase_id === $purchase->id
                && $purchase->licence->first_used_at === null && ! isset($used[$purchase->id])
                && $order->paid_at !== null && now()->lessThan(app(BadgeTemplateRefunds::class)->deadline($purchase))) {
                $capture = $observations->get($order->id)?->firstWhere('processing_state', 'posted');
                if ($capture !== null) {
                    $hold($capture->attempt_id, $order->id, $capture->amount_pesewas ?? $order->charged_pesewas);
                }
            }
            if (in_array($purchase->state, ['pending', 'review'], true)) {
                $unresolved = $order->attempts->contains(fn ($attempt): bool => ! in_array($attempt->status, ['failed', 'expired'], true));
                if ($order->expires_at->isFuture() || $unresolved) {
                    $known = $observations->get($order->id)?->contains(fn ($capture): bool => $capture->amount_pesewas !== null && $capture->amount_pesewas > 0 && $capture->currency === 'GHS' && $capture->account_matches && $capture->reference_matches) ?? false;
                    if (! $known) {
                        $orderMinimums[$order->id] = $order->charged_pesewas;
                    }
                }
            }
        }
        $orders = $orderMinimums;
        $totals = [];
        foreach ($captures as $attemptId => $capture) {
            $totals[$capture['order']] = ($totals[$capture['order']] ?? 0) + max(0, $capture['amount'] - ($confirmed[$attemptId] ?? 0));
        }
        foreach ($totals as $orderId => $amount) {
            $orders[$orderId] = max($orders[$orderId] ?? 0, $amount);
        }

        return $cash - array_sum($orders);
    }

    public function assertCapacity(Tenant $seller, int $amount, ?string $excludeOrder = null): void
    {
        if ($this->available($seller, excludeOrder: $excludeOrder) < $amount) {
            throw ValidationException::withMessages(['checkout' => 'Paid template purchases are paused while the platform refund reserve needs funding.']);
        }
    }

    public function refundBacked(StoreRefund $refund): bool
    {
        return $this->available($refund->order->tenant, excludeCapture: $refund->observation_id) >= $refund->amount_pesewas;
    }
}
