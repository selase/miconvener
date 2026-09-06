<?php

declare(strict_types=1);

namespace App\Console\Commands\Events;

use App\Contracts\SettlementGateway;
use App\Exceptions\PaymentFailedException;
use App\Models\EventLedgerEntry;
use App\Models\EventPayout;
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
    protected $signature = 'payouts:reconcile {--minutes=30 : How long a payout may sit in processing before it is chased}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ask the settlement provider to resolve payouts stuck in processing';

    public function handle(SettlementGateway $settlementGateway): int
    {
        $threshold = now()->subMinutes((int) $this->option('minutes'));

        $stuck = EventPayout::query()
            ->where('status', EventPayout::STATUS_PROCESSING)
            ->whereNotNull('provider_reference')
            ->where('updated_at', '<=', $threshold)
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('No payouts are stuck in processing.');

            return self::SUCCESS;
        }

        $this->info("Reconciling {$stuck->count()} payout(s) stuck in processing.");

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

            $this->resolve($payout, (string) $status);
        }

        return self::SUCCESS;
    }

    private function resolve(EventPayout $payout, string $status): void
    {
        if ($status === 'success') {
            DB::transaction(function () use ($payout): void {
                // Re-read under a lock: the webhook may have landed while the
                // provider was being asked, and it writes the same rows.
                $locked = EventPayout::query()->whereKey($payout->id)->lockForUpdate()->first();

                if (! $locked || $locked->status !== EventPayout::STATUS_PROCESSING) {
                    return;
                }

                $locked->update(['status' => EventPayout::STATUS_PAID, 'paid_at' => now()]);

                if (! $locked->ledgerEntries()->where('type', EventLedgerEntry::TYPE_PAYOUT)->exists()) {
                    EventLedgerEntry::create([
                        'tenant_id' => $locked->tenant_id,
                        'event_id' => $locked->event_id,
                        'type' => EventLedgerEntry::TYPE_PAYOUT,
                        'payout_id' => $locked->id,
                        'gross_amount' => $locked->amount,
                        'gateway_fee_amount' => 0,
                        'commission_amount' => 0,
                        'net_amount' => $locked->amount,
                        'currency' => $locked->event->currency,
                        'provider' => 'paystack',
                        'provider_reference' => $locked->provider_reference,
                    ]);
                }
            });

            $this->line("  {$payout->id}: transfer succeeded — marked paid.");

            return;
        }

        if (in_array($status, ['failed', 'reversed', 'abandoned'], true)) {
            $payout->update([
                'status' => EventPayout::STATUS_FAILED,
                'failure_reason' => "The payment provider reported the transfer as {$status}.",
            ]);

            $this->line("  {$payout->id}: transfer {$status} — marked failed and re-sendable.");

            return;
        }

        // Still in flight (pending, otp, or an unfamiliar status). Leave it be.
        $this->line("  {$payout->id}: still {$status} — left in processing.");
    }
}
