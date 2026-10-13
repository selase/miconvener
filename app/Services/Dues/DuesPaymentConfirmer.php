<?php

declare(strict_types=1);

namespace App\Services\Dues;

use App\Enum\DuesChargeStatusEnum;
use App\Http\Controllers\Tenant\Commerce\WebhookController;
use App\Mail\DuesReceipt;
use App\Models\DuesCharge;
use App\Models\Tenant;
use App\Services\Finance\LedgerService;
use App\Services\Operations\OperationalSignals;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/**
 * Marks the dues charges a confirmed payment covered as paid and books them.
 *
 * Three paths reach it: the platform settlement webhook (platform-default
 * organisations), an organisation's own Paystack webhook (own gateway), and
 * the pay page's callback, which verifies with Paystack when the webhook is
 * late. Each says which account holds the money in `settled_by`, and a
 * payment is booked only when that matches how the organisation settles: a
 * charge paid into an organisation's own account must never be booked as
 * money the platform holds for it, which a payout would then hand over.
 *
 * Idempotent: a charge this payment already paid is left alone, so webhook
 * redelivery and a callback racing its webhook change nothing.
 */
final class DuesPaymentConfirmer
{
    /**
     * The money is in the platform's settlement account.
     */
    public const string SETTLED_BY_PLATFORM = 'platform';

    /**
     * The money is in the organisation's own Paystack account.
     */
    public const string SETTLED_BY_OWN_GATEWAY = 'own_gateway';

    public function __construct(
        private readonly LedgerService $ledgerService,
        private readonly DuesFee $duesFee,
        private readonly OperationalSignals $signals,
    ) {}

    /**
     * Returns the charges this call paid; empty when it paid none, including
     * on a redelivery of a payment already confirmed.
     *
     * @param  array{
     *     metadata?: array<string, mixed>,
     *     amount?: int|numeric-string,
     *     fees?: int|numeric-string,
     *     currency?: string,
     *     provider?: string,
     *     settled_by?: string,
     *     split_received?: int|null
     * }  $gatewayData  What the gateway reported. `split_received` is what the commission subaccount was paid, when Paystack says.
     * @return array<int, DuesCharge>
     */
    public function confirm(string $reference, array $gatewayData): array
    {
        $metadata = (array) ($gatewayData['metadata'] ?? []);
        $chargeIds = array_values(array_unique(array_map('strval', (array) ($metadata['charge_ids'] ?? []))));
        $tenantId = (string) ($metadata['tenant_id'] ?? '');
        $memberId = (string) ($metadata['member_id'] ?? '');

        if ($reference === '' || $chargeIds === [] || $tenantId === '' || $memberId === '') {
            return [];
        }

        $tenant = Tenant::find($tenantId);

        if (! $tenant instanceof Tenant) {
            return [];
        }

        $expectedAccount = $tenant->isPlatformDefaultSettlement() ? self::SETTLED_BY_PLATFORM : self::SETTLED_BY_OWN_GATEWAY;

        if (($gatewayData['settled_by'] ?? null) !== $expectedAccount) {
            Log::error('dues.payment.wrong_account', [
                'tenant_id' => $tenantId,
                'reference' => $reference,
                'settled_by' => $gatewayData['settled_by'] ?? null,
                'expected' => $expectedAccount,
            ]);
            $this->signalUnbooked($reference, 'paid into the wrong account ('.((string) ($gatewayData['settled_by'] ?? 'unknown'))." for an organisation that settles by {$expectedAccount})");

            return [];
        }

        /*
         * A payment the ledger refuses (a gateway fee and commission that
         * swallow the whole amount) is rolled back and left open rather than
         * thrown: a 500 would only have Paystack redeliver it to the same
         * refusal. The signal and log let someone book it by hand.
         */
        try {
            /** @var array<int, DuesCharge> $paid */
            $paid = DB::connection('landlord')->transaction(
                fn (): array => $this->settle($tenant, $memberId, $chargeIds, $reference, $gatewayData, $metadata)
            );
        } catch (InvalidArgumentException $e) {
            Log::error('dues.payment.ledger_failed', [
                'tenant_id' => $tenantId,
                'member_id' => $memberId,
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);
            $this->signalUnbooked($reference, "the ledger refused it: {$e->getMessage()}");

            return [];
        }

        $member = $paid[0]->member ?? null;

        if ($member !== null && filled($member->email)) {
            Mail::to($member->email)->queue(new DuesReceipt($member, new Collection($paid), $reference));
        }

        return $paid;
    }

    /**
     * @param  array<int, string>  $chargeIds
     * @param  array<string, mixed>  $gatewayData
     * @param  array<string, mixed>  $metadata
     * @return array<int, DuesCharge>
     */
    private function settle(Tenant $tenant, string $memberId, array $chargeIds, string $reference, array $gatewayData, array $metadata): array
    {
        /*
         * Scoped to the member the checkout was started for, so a charge list
         * naming someone else's charge cannot pay it.
         */
        $charges = DuesCharge::query()
            ->with('member')
            ->where('tenant_id', $tenant->id)
            ->where('member_id', $memberId)
            ->whereIn('id', $chargeIds)
            ->orderBy('period_start')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($charges->count() !== count($chargeIds)) {
            Log::warning('dues.payment.foreign_charges', [
                'tenant_id' => $tenant->id,
                'member_id' => $memberId,
                'reference' => $reference,
                'ignored_charge_ids' => array_values(array_diff($chargeIds, $charges->pluck('id')->all())),
            ]);
        }

        if ($charges->isEmpty()) {
            return [];
        }

        if ($charges->every(fn (DuesCharge $charge): bool => $this->wasPaidBy($charge, $reference))) {
            return [];
        }

        $currency = (string) $charges->first()->currency;
        $chargesTotal = (int) $charges->sum('amount');
        $gross = (int) ($gatewayData['amount'] ?? 0);
        $reportedCurrency = mb_strtoupper((string) ($gatewayData['currency'] ?? $currency));

        if ($gross < $chargesTotal || $reportedCurrency !== $currency || $charges->pluck('currency')->unique()->count() !== 1) {
            Log::warning('dues.payment.mismatch', [
                'tenant_id' => $tenant->id,
                'member_id' => $memberId,
                'reference' => $reference,
                'amount' => $gross,
                'expected' => $chargesTotal,
                'currency' => $reportedCurrency,
                'expected_currency' => $currency,
            ]);
            $this->signalUnbooked($reference, "{$reportedCurrency} {$gross} does not cover {$currency} {$chargesTotal} of charges");

            return [];
        }

        foreach ($charges as $charge) {
            if ($charge->status !== DuesChargeStatusEnum::Open && ! $this->wasPaidBy($charge, $reference)) {
                $this->flag($charge, $reference);
            }
        }

        $open = $charges->filter(fn (DuesCharge $charge): bool => $charge->status === DuesChargeStatusEnum::Open)->values();

        if ($open->isEmpty()) {
            return [];
        }

        $feeShares = $this->apportionFees($open, max(0, (int) ($gatewayData['fees'] ?? 0)));
        $commissions = $open->mapWithKeys(fn (DuesCharge $charge): array => [$charge->id => $this->duesFee->for((int) $charge->amount)])->all();
        $settlements = $tenant->isPlatformDefaultSettlement()
            ? []
            : $this->allocateOwnGatewayCommission($tenant, $open, $commissions, $currency, $gatewayData, $metadata);
        $provider = (string) ($gatewayData['provider'] ?? 'paystack');

        foreach ($open as $charge) {
            $feeShare = $feeShares[$charge->id];
            $commission = $commissions[$charge->id];
            $ledgerReference = "{$reference}:{$charge->id}";

            $charge->update([
                'status' => DuesChargeStatusEnum::Paid,
                'paid_at' => now(),
                'payment_method' => 'online',
                'payment_reference' => $reference,
                'charged_amount' => (int) $charge->amount,
                'gateway_fee_amount' => $feeShare,
                'commission_amount' => $commission,
            ]);

            if ($tenant->isPlatformDefaultSettlement()) {
                $this->ledgerService->recordDuesPayment($tenant, $charge, (int) $charge->amount, $commission, $ledgerReference, $feeShare);

                continue;
            }

            $this->ledgerService->recordOwnGatewayDuesPayment(
                $tenant,
                $charge,
                (int) $charge->amount,
                $commission,
                $ledgerReference,
                $feeShare,
                $provider,
                $settlements[$charge->id]['received'],
                $settlements[$charge->id]['credit'],
            );
        }

        return $open->all();
    }

    /**
     * Money that reached a gateway but was not booked: raised as a webhook
     * processing failure, which the health check and the daily billing
     * summary both surface.
     */
    private function signalUnbooked(string $reference, string $reason): void
    {
        $this->signals->recordWebhookFailure('processing', "dues payment {$reference} not booked: {$reason}");
    }

    private function wasPaidBy(DuesCharge $charge, string $reference): bool
    {
        return $charge->status === DuesChargeStatusEnum::Paid && $charge->payment_reference === $reference;
    }

    /**
     * Money arrived for a charge that is no longer open. Nothing is booked
     * (refunds are out of scope); the charge carries a note for the
     * organisation to resolve, written once however often the payment is
     * redelivered.
     */
    private function flag(DuesCharge $charge, string $reference): void
    {
        $line = "Payment {$reference} received again after this charge was {$charge->status->value}.";

        if (str_contains((string) $charge->note, "Payment {$reference} ")) {
            return;
        }

        Log::warning('dues.payment.unmatched', [
            'tenant_id' => $charge->tenant_id,
            'member_id' => $charge->member_id,
            'charge_id' => $charge->id,
            'status' => $charge->status->value,
            'reference' => $reference,
        ]);

        $charge->update(['note' => filled($charge->note) ? "{$charge->note}\n{$line}" : $line]);
    }

    /**
     * Splits the gateway fee across the charges this payment pays, in
     * proportion to each charge's amount, rounding down and putting the
     * remainder on the last charge so the shares sum exactly to $fees. A
     * flagged charge takes no share: what stays unbooked for the organisation
     * to resolve is then exactly that charge's face amount.
     *
     * @param  Collection<int, DuesCharge>  $charges
     * @return array<string, int> Keyed by charge id.
     */
    private function apportionFees(Collection $charges, int $fees): array
    {
        $total = (int) $charges->sum('amount');
        $shares = [];
        $allocated = 0;

        foreach ($charges as $charge) {
            $share = $total > 0 ? intdiv($fees * (int) $charge->amount, $total) : 0;
            $shares[$charge->id] = $share;
            $allocated += $share;
        }

        $shares[$charges->last()->id] += $fees - $allocated;

        return $shares;
    }

    /**
     * How each charge's commission was met on an own-gateway payment: the
     * cash the split routed to the platform and the credit netted against it,
     * worked out once for the whole payment and handed out charge by charge.
     *
     * @param  Collection<int, DuesCharge>  $open
     * @param  array<string, int>  $commissions
     * @param  array<string, mixed>  $gatewayData
     * @param  array<string, mixed>  $metadata
     * @return array<string, array{received: int, credit: int}>
     */
    private function allocateOwnGatewayCommission(Tenant $tenant, Collection $open, array $commissions, string $currency, array $gatewayData, array $metadata): array
    {
        $totalCommission = array_sum($commissions);

        /*
         * Credit is drawn down only up to the commission being booked, in
         * case a charge in the payment was flagged rather than paid.
         */
        $metadata['commission_credit_applied'] = min(max(0, (int) ($metadata['commission_credit_applied'] ?? 0)), $totalCommission);

        $splitReceived = $gatewayData['split_received'] ?? null;
        $settlement = WebhookController::commissionSettlement(
            $tenant,
            $currency,
            (string) ($gatewayData['provider'] ?? 'paystack'),
            $metadata,
            $totalCommission,
            is_numeric($splitReceived) ? (int) $splitReceived : null,
        );

        $receivedLeft = $settlement['received'];
        $creditLeft = $settlement['credit'];
        $allocations = [];

        foreach ($open as $charge) {
            $commission = $commissions[$charge->id];
            $received = min($receivedLeft, $commission);
            $credit = min($creditLeft, $commission - $received);
            $receivedLeft -= $received;
            $creditLeft -= $credit;
            $allocations[$charge->id] = ['received' => $received, 'credit' => $credit];
        }

        return $allocations;
    }
}
