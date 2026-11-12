<?php

declare(strict_types=1);

namespace App\Services\Badges;

use App\Models\BadgeTemplateLicenceEvent;
use App\Models\BadgeTemplatePurchase;
use App\Models\BadgeTemplateUse;
use App\Models\LedgerTransaction;
use App\Models\StoreOrder;
use App\Models\StoreRefund;
use App\Models\User;
use App\Services\Marketplace\StoreRefunds;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

final class BadgeTemplateRefunds
{
    public function request(BadgeTemplatePurchase $purchase, User $actor, string $operationKey, string $reason, bool $exception = false): StoreRefund
    {
        $this->authorize($actor);
        $purchase = BadgeTemplatePurchase::query()->whereKey($purchase->id)->firstOrFail();

        return app(StoreRefunds::class)->request($purchase->order, $actor, $operationKey, $reason, null, $exception);
    }

    public function deadline(BadgeTemplatePurchase $purchase): CarbonImmutable
    {
        $paidAt = $purchase->order->paid_at;
        if ($paidAt === null) {
            $this->refuse('Verify the original payment date before approving this refund.');
        }

        return CarbonImmutable::instance($paidAt)->setTimezone($purchase->terms_snapshot['licence_timezone'] ?? 'Africa/Accra')->addDays(7);
    }

    /** The canonical commerce order and licence locks are held by the caller. */
    public function prepare(StoreRefund $refund, bool $exception): void
    {
        $actor = User::withoutGlobalScopes()->whereKey($refund->requested_by)->firstOrFail();
        $this->authorize($actor);
        $purchase = BadgeTemplatePurchase::query()->with('licence')->where('order_id', $refund->order_id)->firstOrFail();
        $type = LedgerTransaction::withoutGlobalScopes()->whereKey($refund->observation->ledger_transaction_id)->value('transaction_type');
        $primary = $type === 'badge_template_sale';
        if ($primary) {
            if ($purchase->licence->current_purchase_id !== $purchase->id || $purchase->state !== 'paid') {
                $this->refuse('This payment is not the current event licence.');
            }
            $used = $purchase->licence->first_used_at !== null || BadgeTemplateUse::query()->where('purchase_id', $purchase->id)->exists();
            if (! $exception && ($used || now()->greaterThanOrEqualTo($this->deadline($purchase)))) {
                $this->refuse('Used licences and requests outside seven calendar days need an explicit platform support exception.');
            }
            $purchase->licence->update(['state' => 'suspended']);
        }
        BadgeTemplateLicenceEvent::query()->create(['licence_id' => $purchase->licence_id, 'purchase_id' => $purchase->id,
            'actor_id' => $actor->id, 'action' => 'refund_approved', 'operation_key' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'badge-refund-approved:'.$refund->operation_key),
            'evidence' => ['refund_id' => $refund->id, 'capture_id' => $refund->observation_id, 'amount_pesewas' => $refund->amount_pesewas,
                'reason' => $refund->reason, 'exception' => $exception, 'primary' => $primary]]);
    }

    public function confirmed(StoreRefund $refund, StoreOrder $order): void
    {
        $purchase = BadgeTemplatePurchase::query()->with('licence')->where('order_id', $order->id)->firstOrFail();
        $type = LedgerTransaction::withoutGlobalScopes()->whereKey($refund->observation->ledger_transaction_id)->value('transaction_type');
        $primary = $type === 'badge_template_sale';
        if ($primary || $purchase->state === 'review') {
            $purchase->update(['state' => 'refunded']);
            $order->update(['payment_state' => 'refunded', 'state' => 'cancelled', 'review_reason' => null]);
            if ($purchase->licence->current_purchase_id === $purchase->id || $purchase->licence->current_purchase_id === null) {
                $purchase->licence->update(['state' => 'refunded']);
            }
        }
        BadgeTemplateLicenceEvent::query()->create(['licence_id' => $purchase->licence_id, 'purchase_id' => $purchase->id,
            'actor_id' => $refund->requested_by, 'action' => 'refund_confirmed', 'operation_key' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'badge-refund-confirmed:'.$refund->id),
            'evidence' => ['refund_id' => $refund->id, 'primary' => $primary]]);
    }

    public function restore(StoreRefund $refund, User $actor, string $operationKey, string $reason): \App\Models\BadgeTemplateLicence
    {
        $this->authorize($actor);
        if (! \Illuminate\Support\Str::isUuid($operationKey) || mb_trim($reason) === '' || mb_strlen($reason) > 500 || $refund->submitted_at === null) {
            $this->refuse('Only a submitted refund with verified failure can have an explicit access restoration.');
        }
        $refund = app(StoreRefunds::class)->reconcile($refund);

        return $refund->getConnection()->transaction(function () use ($refund, $actor, $operationKey, $reason): \App\Models\BadgeTemplateLicence {
            $order = app(\App\Services\Marketplace\CommerceOrderLocks::class)->order($refund->order);
            $this->authorize($actor);
            $refund = StoreRefund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($refund->status !== 'review' || $refund->provider_status !== 'failed' || $refund->provider_processed_at !== null || $refund->review_reason !== 'provider_refund_not_complete') {
                $this->refuse('Verify a definitive failure from the original payment account before restoring access.');
            }
            $purchase = BadgeTemplatePurchase::query()->with('licence')->where('order_id', $order->id)->firstOrFail();
            $key = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'badge-refund-restore:'.$operationKey);
            $prior = BadgeTemplateLicenceEvent::query()->where('licence_id', $purchase->licence_id)->where('operation_key', $key)->first();
            if ($prior !== null && ($prior->evidence['refund_id'] ?? null) === $refund->id
                && ($prior->evidence['reason'] ?? null) === mb_trim($reason) && (string) $prior->actor_id === (string) $actor->id
                && $purchase->licence->state === 'active' && $purchase->licence->current_purchase_id === $purchase->id) {
                return $purchase->licence;
            }
            if ($purchase->licence->state !== 'suspended' || $purchase->licence->current_purchase_id !== $purchase->id || $purchase->state !== 'paid') {
                $this->refuse('The licence has changed since this refund decision.');
            }
            $purchase->licence->update(['state' => 'active']);
            $order->update(['payment_state' => 'paid', 'state' => 'confirmed']);
            BadgeTemplateLicenceEvent::query()->create(['licence_id' => $purchase->licence_id, 'purchase_id' => $purchase->id, 'actor_id' => $actor->id,
                'action' => 'refund_failed_access_restored', 'operation_key' => $key, 'evidence' => ['refund_id' => $refund->id, 'reason' => mb_trim($reason), 'provider_status' => 'failed']]);

            return $purchase->licence;
        });
    }

    public function authorize(User $actor): void
    {
        $actor = User::withoutGlobalScopes()->whereKey($actor->id)->firstOrFail();
        if ($actor->status !== null && $actor->status !== User::STATUS_ACTIVE) {
            throw new \Illuminate\Auth\Access\AuthorizationException;
        }
        Gate::forUser($actor)->authorize('manage-template-refunds');
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['refund' => $message]);
    }
}
