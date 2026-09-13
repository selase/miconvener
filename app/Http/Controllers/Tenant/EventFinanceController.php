<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Contracts\SettlementGateway;
use App\Exceptions\PaymentFailedException;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventLedgerEntry;
use App\Models\EventPayout;
use App\Models\TenantPayoutAccount;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class EventFinanceController extends Controller
{
    public function index(string $subdomain, string $event): JsonResponse
    {
        $this->authorize('read event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $collected = (int) $eventModel->ledgerEntries()->where('type', EventLedgerEntry::TYPE_CHARGE)->sum('gross_amount');
        $fees = (int) $eventModel->ledgerEntries()->where('type', EventLedgerEntry::TYPE_CHARGE)->sum('commission_amount');
        $netCollected = $this->netCollected($eventModel);
        $paidOut = (int) $eventModel->payouts()->where('status', EventPayout::STATUS_PAID)->sum('amount');

        $stats = [
            'collected' => $collected,
            'refunded' => (int) $eventModel->ledgerEntries()->where('type', EventLedgerEntry::TYPE_REFUND)->sum('gross_amount'),
            'fees' => $fees,
            'net' => $collected - $fees,
            'net_collected' => $netCollected,
            'paid_out' => $paidOut,
            'available_balance' => $this->availableBalance($eventModel),
            'confirmed_orders' => $eventModel->registrations()->confirmed()->count(),
        ];

        $accounts = TenantPayoutAccount::where('tenant_id', $tenant->id)->orderByDesc('created_at')->get();

        $ledgerService = app(\App\Services\Finance\LedgerService::class);
        $trialBalance = $ledgerService->getTrialBalance($eventModel);
        $payoutSchedule = $eventModel->payoutSchedule;

        return response()->json([
            'stats' => $stats,
            'trial_balance' => $trialBalance,
            'payout_schedule' => $payoutSchedule ? [
                'id' => $payoutSchedule->id,
                'schedule_type' => $payoutSchedule->schedule_type,
                'days_after_event' => $payoutSchedule->days_after_event,
                'holdback_percentage' => (float) $payoutSchedule->holdback_percentage,
                'holdback_release_days' => $payoutSchedule->holdback_release_days,
                'minimum_payout_amount' => $payoutSchedule->minimum_payout_amount,
                'auto_payout_enabled' => $payoutSchedule->auto_payout_enabled,
                'preferred_account_id' => $payoutSchedule->preferred_account_id,
                'last_reconciled_at' => $payoutSchedule->last_reconciled_at?->toIso8601String(),
            ] : null,
            'accounts' => $accounts->map(fn (TenantPayoutAccount $a): array => [
                'id' => $a->id,
                'type' => $a->type,
                'label' => $a->label,
                'account_name' => $a->account_name,
                'masked_account_number' => $a->maskedAccountNumber(),
                'is_verified' => $a->is_verified,
            ])->values(),
            'payouts' => $eventModel->payouts->map(fn (EventPayout $p): array => [
                'id' => $p->id,
                'amount' => $p->amount,
                'status' => $p->status,
                'scheduled_at' => $p->scheduled_at?->toIso8601String(),
                'paid_at' => $p->paid_at?->toIso8601String(),
                'note' => $p->note,
                'failure_reason' => $p->failure_reason,
                'payout_account_label' => $p->payoutAccount?->label,
                'payout_account_masked_number' => $p->payoutAccount?->maskedAccountNumber(),
            ])->values(),
        ]);
    }

    public function storePayout(Request $request, string $subdomain, string $event): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $validated = $request->validate([
            'payout_account_id' => ['required', Rule::exists('tenant_payout_accounts', 'id')->where('tenant_id', $tenant->id)],
            'amount' => ['required', 'integer', 'min:1'],
            'scheduled_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        return DB::transaction(function () use ($eventModel, $tenant, $validated): JsonResponse {
            // Lock the event row for the balance-check-then-create sequence so
            // two concurrent payout requests (or a payout racing a refund)
            // can't both read the same available balance and both pass,
            // over-drawing the event's collected funds.
            Event::where('id', $eventModel->id)->lockForUpdate()->firstOrFail();

            $available = $this->availableBalance($eventModel);
            if ($validated['amount'] > $available) {
                return response()->json(['message' => "Requested amount ({$validated['amount']}) exceeds the available balance ({$available})."], 422);
            }

            $payout = $eventModel->payouts()->create([
                'tenant_id' => $tenant->id,
                'payout_account_id' => $validated['payout_account_id'],
                'amount' => $validated['amount'],
                'scheduled_at' => $validated['scheduled_at'] ?? null,
                'note' => $validated['note'] ?? null,
            ]);

            return response()->json(['id' => $payout->id], 201);
        });
    }

    public function sendPayout(
        string $subdomain,
        string $event,
        string $payout,
        SettlementGateway $settlementGateway,
        \App\Services\Finance\TransferFeeSchedule $transferFeeSchedule
    ): JsonResponse {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        return DB::transaction(function () use ($eventModel, $payout, $settlementGateway, $transferFeeSchedule): JsonResponse {
            // Lock the payout row for the whole check-send-write sequence so two
            // concurrent "send" requests for the same payout can't both pass the
            // status check and both fire a live Paystack transfer. Each retry
            // deliberately generates a fresh reference, so Paystack's own
            // duplicate-reference protection cannot catch a double send.
            $payoutModel = $eventModel->payouts()->where('id', $payout)->lockForUpdate()->firstOrFail();

            if (! in_array($payoutModel->status, [EventPayout::STATUS_SCHEDULED, EventPayout::STATUS_FAILED], true)) {
                return response()->json(['message' => 'Only a scheduled or failed payout can be sent.'], 422);
            }

            $account = $payoutModel->payoutAccount;
            $currency = $eventModel->currency;

            try {
                if (! $account->recipient_code) {
                    $recipientCode = $settlementGateway->createRecipient(
                        $account->type === TenantPayoutAccount::TYPE_MOBILE_MONEY ? 'mobile_money' : 'nuban',
                        $account->resolved_account_name ?? $account->account_name,
                        (string) $account->account_number_encrypted,
                        (string) $account->bank_code,
                        $currency
                    );
                    $account->update(['recipient_code' => $recipientCode]);
                }

                /*
                 * Paystack sends the recipient this amount and debits the
                 * platform that amount plus its fee. Deducting the fee first
                 * means the platform's balance falls by exactly what it owed,
                 * and the organizer bears the cost of their own payout.
                 */
                $transferFee = $transferFeeSchedule->feeFor($account->type);
                $netToSend = $payoutModel->amount - $transferFee;

                if ($netToSend <= 0) {
                    return response()->json([
                        'message' => 'This balance is smaller than the transfer fee, so it cannot be sent on its own. It will be paid out with the next batch.',
                    ], 422);
                }

                $reference = 'payout_'.$payoutModel->id.'_'.Str::random(8);
                $transfer = $settlementGateway->initiateTransfer($account->recipient_code, $netToSend, $currency, $reference);
            } catch (PaymentFailedException $e) {
                return response()->json(['message' => 'Could not send payout: '.$e->getMessage()], 502);
            }

            // Paystack accepts the request but parks the transfer when the platform
            // account still requires an OTP per transfer. No money moves and no
            // webhook follows, so recording it as processing would tell the
            // organizer they had been paid and then freeze the record, since a
            // processing payout cannot be corrected by hand.
            $transferStatus = (string) ($transfer['status'] ?? '');

            if (! in_array($transferStatus, ['success', 'pending', 'queued'], true)) {
                $payoutModel->update([
                    'status' => EventPayout::STATUS_FAILED,
                    'failure_reason' => $transferStatus === 'otp'
                        ? 'The payment provider is asking for a one-time code before it will release transfers. Turn off per-transfer OTP on the platform Paystack account, then send this payout again.'
                        : "The payment provider did not release the transfer (status: {$transferStatus}).",
                ]);

                return response()->json([
                    'message' => $payoutModel->failure_reason,
                ], 502);
            }

            $payoutModel->update([
                'status' => EventPayout::STATUS_PROCESSING,
                'provider_reference' => $reference,
                'failure_reason' => null,
                'transfer_fee_amount' => $transferFeeSchedule->feeFromProviderResponse($transfer) ?? $transferFee,
                'net_paid_amount' => $netToSend,
            ]);

            return response()->json(['message' => 'Payout sent.', 'transfer_code' => $transfer['transfer_code']]);
        });
    }

    public function updatePayoutStatus(Request $request, string $subdomain, string $event, string $payout): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $validated = $request->validate([
            'status' => ['required', Rule::in([EventPayout::STATUS_SCHEDULED, EventPayout::STATUS_PAID])],
        ]);

        return DB::transaction(function () use ($eventModel, $payout, $validated): JsonResponse {
            // Locked for the same reason as sending: a live transfer must not be
            // overwritten by a manual status change decided from stale data.
            $payoutModel = $eventModel->payouts()->where('id', $payout)->lockForUpdate()->firstOrFail();

            if ($payoutModel->status === EventPayout::STATUS_PROCESSING) {
                return response()->json(['message' => 'This payout is currently being sent and cannot be manually updated. Wait for the transfer to complete, or check back shortly.'], 422);
            }

            $markingPaid = $validated['status'] === EventPayout::STATUS_PAID;

            $payoutModel->update([
                'status' => $validated['status'],
                'paid_at' => $markingPaid ? now() : null,
            ]);

            // A payout settled outside the platform still has to reach the ledger,
            // or it is missing from the settlement statement's line items while
            // still counting against the reconciliation totals.
            if ($markingPaid) {
                // Both writes record the same event: the single-row summary
                // entry and the double-entry ledger transaction. Guarding only
                // the first let a second "mark as paid" post a second
                // LedgerTransaction while looking idempotent from the
                // EventLedgerEntry side alone.
                if (! $payoutModel->ledgerEntries()->where('type', EventLedgerEntry::TYPE_PAYOUT)->exists()) {
                    // Post into double-entry accounting ledger
                    app(\App\Services\Finance\LedgerService::class)->recordPayout(
                        $eventModel,
                        $payoutModel->amount,
                        $payoutModel->provider_reference ?? ('PO-'.$payoutModel->id),
                        $payoutModel,
                        (int) $payoutModel->transfer_fee_amount,
                        false,
                        'manual',
                    );
                }
            }

            return response()->json(['message' => 'Payout updated.']);
        });
    }

    public function updatePayoutSchedule(Request $request, string $subdomain, string $event): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $validated = $request->validate([
            'schedule_type' => ['required', Rule::in(['manual', 'immediate', 'post_event'])],
            'days_after_event' => ['nullable', 'integer', 'min:0', 'max:90'],
            'holdback_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'holdback_release_days' => ['nullable', 'integer', 'min:0', 'max:180'],
            'minimum_payout_amount' => ['nullable', 'integer', 'min:0'],
            'auto_payout_enabled' => ['boolean'],
            'preferred_account_id' => ['nullable', Rule::exists('tenant_payout_accounts', 'id')->where('tenant_id', $tenant->id)],
        ]);

        $schedule = \App\Models\EventPayoutSchedule::updateOrCreate(
            ['event_id' => $eventModel->id],
            [
                'tenant_id' => $tenant->id,
                'schedule_type' => $validated['schedule_type'],
                'days_after_event' => $validated['days_after_event'] ?? 0,
                'holdback_percentage' => $validated['holdback_percentage'],
                'holdback_release_days' => $validated['holdback_release_days'] ?? 14,
                'minimum_payout_amount' => $validated['minimum_payout_amount'] ?? 0,
                'auto_payout_enabled' => $validated['auto_payout_enabled'] ?? false,
                'preferred_account_id' => $validated['preferred_account_id'] ?? null,
            ]
        );

        return response()->json([
            'message' => 'Payout schedule updated successfully.',
            'payout_schedule' => [
                'id' => $schedule->id,
                'schedule_type' => $schedule->schedule_type,
                'days_after_event' => $schedule->days_after_event,
                'holdback_percentage' => (float) $schedule->holdback_percentage,
                'holdback_release_days' => $schedule->holdback_release_days,
                'minimum_payout_amount' => $schedule->minimum_payout_amount,
                'auto_payout_enabled' => $schedule->auto_payout_enabled,
                'preferred_account_id' => $schedule->preferred_account_id,
            ],
        ]);
    }

    public function exportSettlementStatement(string $subdomain, string $event): StreamedResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);
        $ownGateway = ! $tenant->isPlatformDefaultSettlement();

        $filename = "{$eventModel->slug}-settlement-statement.csv";

        return response()->streamDownload(function () use ($eventModel, $ownGateway): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['Type', 'Registration/Reference', 'Gross', 'Gateway fee', 'Commission', 'Net', 'Currency', 'Date']);

            $eventModel->ledgerEntries()->orderBy('created_at')->chunk(200, function ($entries) use ($handle, $ownGateway): void {
                foreach ($entries as $entry) {
                    fputcsv($handle, [
                        $entry->type,
                        $entry->registration?->full_name ?? $entry->provider_reference,
                        $entry->gross_amount,
                        $ownGateway ? 'not tracked' : $entry->gateway_fee_amount,
                        $entry->commission_amount,
                        $entry->net_amount,
                        $entry->currency,
                        $entry->created_at->toIso8601String(),
                    ]);
                }
            });

            fputcsv($handle, []);
            fputcsv($handle, ['Reconciliation']);
            fputcsv($handle, ['Collected (gross)', $eventModel->ledgerEntries()->where('type', EventLedgerEntry::TYPE_CHARGE)->sum('gross_amount')]);
            fputcsv($handle, ['Paid out', $eventModel->payouts()->where('status', EventPayout::STATUS_PAID)->sum('amount')]);
            fputcsv($handle, ['Available balance', $eventModel->ledgerEntries()->whereIn('type', [EventLedgerEntry::TYPE_CHARGE, EventLedgerEntry::TYPE_REFUND])->sum('net_amount') - $eventModel->payouts()->whereIn('status', [EventPayout::STATUS_SCHEDULED, EventPayout::STATUS_PROCESSING, EventPayout::STATUS_PAID])->sum('amount')]);

            fputcsv($handle, []);
            fputcsv($handle, ['Double-Entry General Ledger (Trial Balance)']);
            fputcsv($handle, ['Account Code', 'Account Name', 'Type', 'Total Debits', 'Total Credits', 'Net Balance']);

            $ledgerService = app(\App\Services\Finance\LedgerService::class);
            $trialBalance = $ledgerService->getTrialBalance($eventModel);

            foreach ($trialBalance['accounts'] as $acc) {
                fputcsv($handle, [
                    $acc['code'],
                    $acc['name'],
                    $acc['type'],
                    $acc['debits'],
                    $acc['credits'],
                    $acc['balance'],
                ]);
            }

            fputcsv($handle, [
                'TOTAL',
                $trialBalance['is_balanced'] ? 'EQUILIBRIUM BALANCED' : 'UNBALANCED WARNING',
                '',
                $trialBalance['total_debits'],
                $trialBalance['total_credits'],
                $trialBalance['total_debits'] - $trialBalance['total_credits'],
            ]);

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function netCollected(Event $eventModel): int
    {
        return (int) $eventModel->ledgerEntries()->whereIn('type', [EventLedgerEntry::TYPE_CHARGE, EventLedgerEntry::TYPE_REFUND])->sum('net_amount');
    }

    private function availableBalance(Event $eventModel): int
    {
        $committedToPayouts = (int) $eventModel->payouts()
            ->whereIn('status', [EventPayout::STATUS_SCHEDULED, EventPayout::STATUS_PROCESSING, EventPayout::STATUS_PAID])
            ->sum('amount');

        return $this->netCollected($eventModel) - $committedToPayouts;
    }

    private function findEvent(string $tenantId, string $eventId): Event
    {
        return Event::where('tenant_id', $tenantId)->where('id', $eventId)->firstOrFail();
    }

    private function getTenant()
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(403, 'Tenant context not resolved.');
        }

        return $tenant;
    }
}
