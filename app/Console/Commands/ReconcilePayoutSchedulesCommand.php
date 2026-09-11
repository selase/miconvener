<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\EventLedgerEntry;
use App\Models\EventPayout;
use App\Models\EventPayoutSchedule;
use App\Models\LedgerAccount;
use App\Models\TenantPayoutAccount;
use App\Services\Finance\LedgerService;
use Illuminate\Console\Command;

final class ReconcilePayoutSchedulesCommand extends Command
{
    protected $signature = 'app:reconcile-payout-schedules {--event= : Specific event ID to reconcile}';

    protected $description = 'Automate settlement reconciliation, holdback reserve enforcement, and scheduled event payouts';

    public function handle(LedgerService $ledgerService): int
    {
        $this->info('Starting payout schedule reconciliation...');

        $query = EventPayoutSchedule::where('status', EventPayoutSchedule::STATUS_ACTIVE)
            ->with(['event.tenant']);

        if ($eventId = $this->option('event')) {
            $query->where('event_id', $eventId);
        }

        $schedules = $query->get();
        $this->line("Found {$schedules->count()} active payout schedule(s).");

        foreach ($schedules as $schedule) {
            $event = $schedule->event;
            if (! $event) {
                continue;
            }

            $this->reconcileEventSchedule($schedule, $event, $ledgerService);
        }

        $this->info('Payout schedule reconciliation complete.');

        return self::SUCCESS;
    }

    private function reconcileEventSchedule(
        EventPayoutSchedule $schedule,
        Event $event,
        LedgerService $ledgerService
    ): void {
        $now = now();
        $this->line("Processing Event: {$event->name} ({$event->id})");

        // 1. Check Holdback Release if past release days
        $holdbackAccount = LedgerAccount::on('landlord')
            ->where('event_id', $event->id)
            ->where('code', LedgerAccount::CODE_HOLDBACK_RESERVE)
            ->first();

        $currentHoldback = $holdbackAccount ? $holdbackAccount->currentBalance() : 0;

        if ($event->ends_at && $now->gte($event->ends_at->copy()->addDays($schedule->holdback_release_days))) {
            if ($currentHoldback > 0) {
                $this->info("Releasing holdback reserve of {$currentHoldback} for event {$event->name}");
                $ledgerService->recordHoldbackRelease(
                    $event,
                    $currentHoldback,
                    "REL-{$event->id}-{$now->timestamp}",
                    "Automated release of holdback buffer ({$schedule->holdback_release_days} days post-event)"
                );
                $currentHoldback = 0;
            }
        }

        // 2. Check Holdback Retention if post_event and within retention period
        if ($schedule->isPostEvent() && $event->ends_at && $now->gte($event->ends_at->copy()->addDays($schedule->days_after_event))) {
            $clearingAccount = LedgerAccount::on('landlord')
                ->where('event_id', $event->id)
                ->where('code', LedgerAccount::CODE_GATEWAY_CLEARING)
                ->first();

            $grossCollected = $clearingAccount && $clearingAccount->entries()->exists()
                ? (int) $clearingAccount->entries()->where('direction', \App\Models\LedgerEntry::DIRECTION_DEBIT)->sum('amount')
                : (int) $event->ledgerEntries()->where('type', EventLedgerEntry::TYPE_CHARGE)->sum('gross_amount');

            $requiredHoldback = (int) round($grossCollected * ($schedule->holdback_percentage / 100));

            if ($currentHoldback < $requiredHoldback) {
                $holdbackDelta = $requiredHoldback - $currentHoldback;
                $this->info("Retaining holdback reserve of {$holdbackDelta} ({$schedule->holdback_percentage}%) for event {$event->name}");
                $ledgerService->recordHoldbackRetention(
                    $event,
                    $holdbackDelta,
                    "HOLD-{$event->id}-{$now->timestamp}",
                    "Automated retention of {$schedule->holdback_percentage}% holdback buffer"
                );
            }
        }

        // 3. Compute available balance and disburse payout if threshold reached
        $payableAccount = LedgerAccount::on('landlord')
            ->where('event_id', $event->id)
            ->where('code', LedgerAccount::CODE_ORGANIZER_PAYABLE)
            ->first();

        if ($payableAccount && $payableAccount->entries()->exists()) {
            $netPayable = max(0, $payableAccount->fresh()->currentBalance());
        } else {
            $collected = (int) $event->ledgerEntries()->where('type', EventLedgerEntry::TYPE_CHARGE)->sum('gross_amount');
            $refunded = (int) $event->ledgerEntries()->where('type', EventLedgerEntry::TYPE_REFUND)->sum('gross_amount');
            $fees = (int) $event->ledgerEntries()->where('type', EventLedgerEntry::TYPE_CHARGE)->sum('commission_amount');
            $paidOut = (int) $event->payouts()->where('status', EventPayout::STATUS_PAID)->sum('amount');
            $holdbackBalance = $holdbackAccount ? $holdbackAccount->fresh()->currentBalance() : 0;
            $netPayable = max(0, $collected - $refunded - $fees - $paidOut - $holdbackBalance);
        }

        $shouldDisburse = match (true) {
            ! $schedule->auto_payout_enabled => false,
            $schedule->isImmediate() => $netPayable >= $schedule->minimum_payout_amount,
            $schedule->isPostEvent() => $event->ends_at && $now->gte($event->ends_at->copy()->addDays($schedule->days_after_event)) && $netPayable >= $schedule->minimum_payout_amount,
            default => false,
        };

        if ($shouldDisburse && $netPayable > 0) {
            $payoutAccount = null;
            if ($schedule->preferred_account_id) {
                $payoutAccount = TenantPayoutAccount::where('tenant_id', $event->tenant_id)
                    ->where('id', $schedule->preferred_account_id)
                    ->first();
            }

            if (! $payoutAccount) {
                $payoutAccount = TenantPayoutAccount::where('tenant_id', $event->tenant_id)
                    ->where('is_verified', true)
                    ->orderByDesc('created_at')
                    ->first();
            }

            if ($payoutAccount) {
                $this->info("Scheduling automated payout of {$netPayable} for {$event->name} to {$payoutAccount->label}");

                $payout = EventPayout::create([
                    'tenant_id' => $event->tenant_id,
                    'event_id' => $event->id,
                    'payout_account_id' => $payoutAccount->id,
                    'amount' => $netPayable,
                    'status' => EventPayout::STATUS_SCHEDULED,
                    'scheduled_at' => $now,
                    'note' => "Automated settlement payout ({$schedule->schedule_type})",
                ]);

                $ledgerService->recordPayout($event, $netPayable, "PO-{$payout->id}", $payout);
            } else {
                $this->warn("No verified payout account found for tenant {$event->tenant_id}. Payout skipped.");
            }
        }

        $schedule->update([
            'last_reconciled_at' => $now,
            'next_scheduled_run_at' => $now->copy()->addDay(),
        ]);
    }
}
