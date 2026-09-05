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
            'fees' => $fees,
            'net' => $collected - $fees,
            'net_collected' => $netCollected,
            'paid_out' => $paidOut,
            'available_balance' => $this->availableBalance($eventModel),
            'confirmed_orders' => $eventModel->registrations()->confirmed()->count(),
        ];

        $accounts = TenantPayoutAccount::where('tenant_id', $tenant->id)->orderByDesc('created_at')->get();

        return response()->json([
            'stats' => $stats,
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

    public function sendPayout(string $subdomain, string $event, string $payout, SettlementGateway $settlementGateway): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        return DB::transaction(function () use ($eventModel, $payout, $settlementGateway): JsonResponse {
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

                $reference = 'payout_'.$payoutModel->id.'_'.Str::random(8);
                $transfer = $settlementGateway->initiateTransfer($account->recipient_code, $payoutModel->amount, $currency, $reference);
            } catch (PaymentFailedException $e) {
                return response()->json(['message' => 'Could not send payout: '.$e->getMessage()], 502);
            }

            $payoutModel->update([
                'status' => EventPayout::STATUS_PROCESSING,
                'provider_reference' => $reference,
                'failure_reason' => null,
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

        $payoutModel = $eventModel->payouts()->where('id', $payout)->firstOrFail();
        $payoutModel->update([
            'status' => $validated['status'],
            'paid_at' => $validated['status'] === EventPayout::STATUS_PAID ? now() : null,
        ]);

        return response()->json(['message' => 'Payout updated.']);
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
