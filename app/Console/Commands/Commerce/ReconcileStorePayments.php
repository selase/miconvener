<?php

declare(strict_types=1);

namespace App\Console\Commands\Commerce;

use App\Models\StoreOrder;
use App\Models\StorePaymentAttempt;
use App\Models\StorePaymentObservation;
use App\Services\Marketplace\CommerceInventoryLocks;
use App\Services\Marketplace\PurchaseReservations;
use App\Services\Marketplace\StorePaymentConfirmer;
use App\Services\Marketplace\StorePaymentNotifications;
use Illuminate\Console\Command;
use Throwable;

final class ReconcileStorePayments extends Command
{
    protected $signature = 'commerce:reconcile {--limit=100 : Maximum captures and attempts per run}';

    protected $description = 'Recover captured commerce payments, verify unresolved attempts and release expired unpaid holds';

    public function handle(StorePaymentConfirmer $confirmer, StorePaymentNotifications $notifications, CommerceInventoryLocks $locks, PurchaseReservations $reservations, \App\Services\Marketplace\StoreRefunds $refunds, \App\Services\Marketplace\StorePayouts $payouts): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($limit === false) {
            $this->error('Use a limit from 1 to 500.');

            return self::FAILURE;
        }
        $failures = 0;
        foreach (StorePaymentObservation::query()->where('processing_state', 'pending')->orderBy('updated_at')->limit($limit)->get() as $capture) {
            try {
                $capture->touch();
                $confirmer->confirm($capture);
            } catch (Throwable) {
                $failures++;
                $this->warn('A recorded capture still needs reconciliation: '.$capture->id);
            }
        }
        $attempts = StorePaymentAttempt::query()->whereIn('status', ['initializing', 'unknown', 'ready', 'pending'])
            ->where('created_at', '<=', now()->subMinute())->where(fn ($q) => $q->whereNull('last_verified_at')->orWhere('last_verified_at', '<=', now()->subMinutes(5)))
            ->whereDoesntHave('observations')->orderBy('updated_at')->limit(min($limit, 10))->get();
        foreach ($attempts as $attempt) {
            try {
                $notifications->reconcile($attempt);
            } catch (Throwable) {
                StorePaymentAttempt::query()->whereKey($attempt->id)->update(['last_verified_at' => now()]);
                $failures++;
                $this->warn('An unresolved attempt still needs verification: '.$attempt->id);
            }
        }
        $expired = StoreOrder::query()->whereIn('state', ['quoted', 'awaiting_payment'])->where('payment_state', 'awaiting')->where('expires_at', '<=', now())->orderBy('expires_at')->limit($limit)->get();
        foreach ($expired as $order) {
            $order->getConnection()->transaction(function () use ($order, $locks, $reservations): void {
                $order = $locks->order($order);
                if ($order->expires_at->isFuture() || $order->payment_state !== 'awaiting') {
                    return;
                }
                $reservations->release($order, 'expired');
                $unresolved = StorePaymentAttempt::query()->where('order_id', $order->id)->whereNotIn('status', ['failed', 'expired'])->exists();
                $order->update(['state' => $unresolved ? 'review' : 'cancelled', 'review_reason' => $unresolved ? 'expired_unresolved_attempt' : null,
                    'fulfilment_state' => $unresolved ? 'awaiting_preparation' : 'cancelled']);
            });
        }
        foreach (\App\Models\StoreRefund::query()->where('status', '<>', 'confirmed')->where('updated_at', '<=', now()->subMinute())->orderBy('updated_at')->limit(min($limit, 10))->get() as $refund) {
            try {
                $refund->touch();
                $refunds->reconcile($refund);
            } catch (Throwable) {
                $failures++;
                $this->warn('A refund still needs reconciliation: '.$refund->id);
            }
        }
        foreach (\App\Models\EventPayout::withoutGlobalScopes()->where('category', \App\Enum\LedgerCategoryEnum::Commerce)->whereIn('status', [\App\Models\EventPayout::STATUS_PROCESSING, \App\Models\EventPayout::STATUS_AWAITING_OTP])->where('updated_at', '<=', now()->subMinute())->orderBy('updated_at')->limit(min($limit, 10))->get() as $payout) {
            try {
                $payout->touch();
                $payouts->reconcile($payout);
            } catch (Throwable) {
                $failures++;
                $this->warn('A payout still needs reconciliation: '.$payout->id);
            }
        }
        $this->info('Commerce reconciliation finished; unresolved operations: '.$failures);

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
