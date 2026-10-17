<?php

declare(strict_types=1);

namespace App\Console\Commands\Events;

use App\Contracts\SettlementGateway;
use App\Enum\LedgerCategoryEnum;
use App\Exceptions\PaymentFailedException;
use App\Models\Event;
use App\Models\EventLedgerEntry;
use App\Models\EventPayout;
use App\Services\Finance\LedgerService;
use App\Services\Finance\PayoutRelease;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A payout sits in "processing" from the moment its transfer is accepted until
 * the transfer webhook resolves it. If that webhook is never delivered the
 * payout stays there for good: the money has left the platform account, the
 * record never catches up, and the status is deliberately protected from manual
 * correction. This command asks the provider what actually happened.
 */
final class ReconcileStuckPayouts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payouts:reconcile
        {--minutes=30 : How long a payout may sit in processing before it is chased}
        {--otp-minutes=35 : How long a payout may wait for a one-time code before its transfer is checked for expiry}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ask the settlement provider to resolve payouts stuck in processing or left waiting for an expired one-time code';

    public function handle(SettlementGateway $settlementGateway): int
    {
        $threshold = now()->subMinutes((int) $this->option('minutes'));
        $otpThreshold = now()->subMinutes((int) $this->option('otp-minutes'));

        /*
         * A payout parked for a one-time code holds its balance until the
         * code is entered. Paystack abandons the transfer once the code
         * expires, after which no code is accepted, so a payout still waiting
         * past the code's lifetime is checked and failed if the transfer died.
         */
        $stuck = EventPayout::query()
            ->whereNotNull('provider_reference')
            ->where(fn ($query) => $query
                ->where(fn ($processing) => $processing->where('status', EventPayout::STATUS_PROCESSING)->where('updated_at', '<=', $threshold))
                ->orWhere(fn ($awaiting) => $awaiting->where('status', EventPayout::STATUS_AWAITING_OTP)->where('updated_at', '<=', $otpThreshold)))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('No payouts are stuck in processing or waiting on an expired code.');

            return self::SUCCESS;
        }

        $this->info("Reconciling {$stuck->count()} stuck payout(s).");

        foreach ($stuck as $payout) {
            try {
                $status = $settlementGateway->verifyTransfer((string) $payout->provider_reference)['status'] ?? '';
            } catch (PaymentFailedException|Throwable $e) {
                // Leave the payout alone and try again on the next run: a provider
                // outage must never be recorded as a failed transfer.
                Log::warning("Could not verify payout {$payout->id}: ".$e->getMessage());
                $this->warn("  {$payout->id}: could not verify — {$e->getMessage()}");

                continue;
            }

            try {
                $this->resolve($payout, (string) $status);
            } catch (Throwable $e) {
                // One payout that cannot be settled must not stop the rest of
                // the run; it stays in processing for the next one.
                Log::error("Could not reconcile payout {$payout->id}: ".$e->getMessage());
                $this->error("  {$payout->id}: could not reconcile — {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    private function resolve(EventPayout $payout, string $status): void
    {
        if ($status === 'success' && $payout->category === LedgerCategoryEnum::Giving) {
            app(\App\Services\Finance\CommunityPayouts::class)->settleTransfer($payout, (string) $payout->provider_reference);

            return;
        }

        if ($payout->status === EventPayout::STATUS_AWAITING_OTP) {
            if (in_array($status, PayoutRelease::DEAD_TRANSFER_STATUSES, true)
                && $this->markFailed($payout, EventPayout::STATUS_AWAITING_OTP, PayoutRelease::OTP_EXPIRED_REASON)) {
                $this->line("  {$payout->id}: code expired (transfer {$status}) — marked failed and re-sendable.");

                return;
            }

            $this->line("  {$payout->id}: still {$status} — left waiting for its code.");

            return;
        }

        if ($status === 'success') {
            DB::transaction(function () use ($payout): void {
                // Re-read under a lock: the webhook may have landed while the
                // provider was being asked, and it writes the same rows.
                $locked = EventPayout::query()->whereKey($payout->id)->lockForUpdate()->first();

                if (! $locked || $locked->status !== EventPayout::STATUS_PROCESSING) {
                    return;
                }

                $locked->update(['status' => EventPayout::STATUS_PAID, 'paid_at' => now()]);
                $ledger = app(LedgerService::class);

                /*
                 * The same branches as the transfer webhook: a dues payout is
                 * posted in the dues category on the transfer reference the
                 * webhook would use, so whichever lands first posts it once;
                 * any other payout without an event is settled elsewhere.
                 */
                if ($locked->category === LedgerCategoryEnum::Dues) {
                    $ledger->recordDuesPayout(
                        $locked->tenant,
                        (int) $locked->amount,
                        (string) $locked->provider_reference,
                        $locked,
                        (string) $locked->currency
                    );

                    return;
                }

                $event = $locked->event;

                if (! $event instanceof Event) {
                    Log::warning('Reconciled transfer for an event-less payout outside the dues category; no ledger posting made here', [
                        'payout_id' => $locked->id,
                        'category' => $locked->category?->value,
                    ]);

                    return;
                }

                if (! $locked->ledgerEntries()->where('type', EventLedgerEntry::TYPE_PAYOUT)->exists()) {
                    // Inside the same idempotency guard as the entry above, so
                    // a re-run cannot double-post.
                    $ledger->recordPayout(
                        $event,
                        (int) $locked->amount,
                        (string) $locked->provider_reference,
                        $locked
                    );
                }
            });

            $this->line("  {$payout->id}: transfer succeeded — marked paid.");

            return;
        }

        if (in_array($status, PayoutRelease::DEAD_TRANSFER_STATUSES, true)) {
            if ($this->markFailed($payout, EventPayout::STATUS_PROCESSING, "The payment provider reported the transfer as {$status}.")) {
                $this->line("  {$payout->id}: transfer {$status} — marked failed and re-sendable.");
            }

            return;
        }

        // Still in flight (pending, otp, or an unfamiliar status). Leave it be.
        $this->line("  {$payout->id}: still {$status} — left in processing.");
    }

    /**
     * Fails the payout if it is still in $expectedStatus, re-read under a lock
     * because a webhook or a code being entered may have moved it meanwhile.
     */
    private function markFailed(EventPayout $payout, string $expectedStatus, string $reason): bool
    {
        return DB::transaction(function () use ($payout, $expectedStatus, $reason): bool {
            $locked = EventPayout::query()->whereKey($payout->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== $expectedStatus) {
                return false;
            }

            $locked->update(['status' => EventPayout::STATUS_FAILED, 'failure_reason' => $reason]);

            return true;
        });
    }
}
