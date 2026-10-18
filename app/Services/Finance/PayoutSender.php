<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Contracts\SettlementGateway;
use App\Exceptions\PaymentFailedException;
use App\Models\EventPayout;
use App\Models\TenantPayoutAccount;
use Illuminate\Support\Str;

/**
 * Send one payout through the settlement gateway: register the recipient if
 * needed, deduct the transfer fee, start the transfer and record the outcome
 * on the payout.
 *
 * Event callers keep their existing transaction and row lock. Community
 * callers commit a processing claim and original reference before the provider
 * call; response updates must still match that claim, without holding database
 * locks across the network or replacing a newer transfer.
 */
final class PayoutSender
{
    public function __construct(
        private readonly SettlementGateway $settlementGateway,
        private readonly TransferFeeSchedule $transferFeeSchedule,
    ) {}

    /**
     * @return array{message: string, http_status: int, status?: string, transfer_code?: mixed}
     */
    public function send(EventPayout $payoutModel, string $currency, ?string $reservedReference = null): array
    {
        $event = $payoutModel->event_id !== null ? \App\Models\Event::withoutGlobalScopes()->where('tenant_id', $payoutModel->tenant_id)->find($payoutModel->event_id) : null;
        $balance = app(\App\Services\Events\MemberBenefitBalance::class);
        if ($event !== null) {
            return $payoutModel->getConnection()->transaction(function () use ($payoutModel, $currency, $reservedReference, $event, $balance): array {
                $locked = \App\Models\Event::withoutGlobalScopes()->whereKey($event->id)->lockForUpdate()->firstOrFail();
                if ($balance->applies($locked) && ($currency !== $payoutModel->currency || $currency !== $locked->currency || $payoutModel->amount > $balance->available($locked, $payoutModel->id))) {
                    return ['message' => 'This payout exceeds the funds available after member-payment and refund holds.', 'http_status' => 422];
                }

                return $this->sendTransfer($payoutModel, $currency, $reservedReference);
            });
        }

        return $this->sendTransfer($payoutModel, $currency, $reservedReference);
    }

    /** @return array{message:string,http_status:int,status?:string,transfer_code?:mixed} */
    private function sendTransfer(EventPayout $payoutModel, string $currency, ?string $reservedReference = null): array
    {
        $account = $payoutModel->payoutAccount;

        $transferSubmitted = false;
        try {
            if (! $account->recipient_code) {
                $recipientCode = $this->settlementGateway->createRecipient(
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
            $transferFee = $this->transferFeeSchedule->feeFor($account->type);
            $netToSend = $payoutModel->amount - $transferFee;

            if ($netToSend <= 0) {
                return [
                    'message' => 'This balance is smaller than the transfer fee, so it cannot be sent on its own. It will be paid out with the next batch.',
                    'http_status' => 422,
                ];
            }

            $payoutModel->update(['requested_transfer_fee_amount' => $transferFee, 'requested_net_amount' => $netToSend]);
            $reference = $reservedReference ?? 'payout_'.$payoutModel->id.'_'.Str::random(8);
            $transferSubmitted = true;
            $transfer = $this->settlementGateway->initiateTransfer($account->recipient_code, $netToSend, $currency, $reference);
        } catch (PaymentFailedException $e) {
            if ($payoutModel->fresh()->status === EventPayout::STATUS_PAID) {
                return ['message' => 'Payout confirmed.', 'http_status' => 200];
            }

            if (! $transferSubmitted && $reservedReference !== null) {
                EventPayout::withoutGlobalScopes()->whereKey($payoutModel->id)->where('status', EventPayout::STATUS_PROCESSING)->where('provider_reference', $reservedReference)->update(['status' => EventPayout::STATUS_FAILED, 'failure_reason' => 'Recipient registration failed before any transfer was submitted.']);
            }

            return ['message' => 'Could not send payout: '.$e->providerMessage(), 'http_status' => 502];
        }

        $current = $payoutModel->fresh();
        if ($current->status === EventPayout::STATUS_PAID) {
            return ['message' => 'Payout confirmed.', 'http_status' => 200];
        }
        if ($reservedReference !== null && ($current->provider_reference !== $reservedReference || $current->status !== EventPayout::STATUS_PROCESSING)) {
            return ['message' => 'The payout state changed while waiting for the provider. Refresh to see its current transfer.', 'http_status' => 409];
        }
        $transferStatus = (string) ($transfer['status'] ?? '');

        /*
         * Paystack parks the transfer when the account requires a one-time
         * code per transfer. No money has moved and no webhook follows, but
         * this is not a failure either — the transfer is real and waiting.
         * Park the payout in the same state so it can be released with the
         * code rather than re-sent, which would create a second transfer.
         */
        if ($transferStatus === 'otp') {
            $transferCode = (string) ($transfer['transfer_code'] ?? '');

            /*
             * Community claims retain the original reference for verification;
             * an incomplete response is not proof that no transfer exists.
             * Keep the legacy event sender's existing retry policy.
             */
            if ($transferCode === '') {
                $this->updateIfUnpaid($payoutModel, [
                    'status' => $reservedReference === null ? EventPayout::STATUS_FAILED : EventPayout::STATUS_PROCESSING,
                    'failure_reason' => $reservedReference === null
                        ? 'The payment provider asked for a one-time code but returned no transfer to release. Send this payout again.'
                        : 'The provider response is incomplete. Reconcile the original transfer; do not send another payout.',
                ], $reservedReference);

                return ['message' => $payoutModel->failure_reason, 'http_status' => 502];
            }

            $this->updateIfUnpaid($payoutModel, [
                'status' => EventPayout::STATUS_AWAITING_OTP,
                'provider_reference' => $reference,
                'provider_transfer_code' => $transferCode,
                'failure_reason' => null,
            ], $reservedReference);

            return [
                'message' => 'Paystack sent a one-time code to the account owner. Enter it to release this payout.',
                'status' => EventPayout::STATUS_AWAITING_OTP,
                'http_status' => 200,
            ];
        }

        if (! in_array($transferStatus, ['success', 'pending', 'queued'], true)) {
            $uncertain = $reservedReference !== null && ! in_array($transferStatus, PayoutRelease::DEAD_TRANSFER_STATUSES, true);
            $this->updateIfUnpaid($payoutModel, [
                'status' => $uncertain ? EventPayout::STATUS_PROCESSING : EventPayout::STATUS_FAILED,
                'failure_reason' => $uncertain ? 'The transfer outcome is unknown. Reconcile the original reference; do not send another payout.' : "The payment provider did not release the transfer (status: {$transferStatus}).",
            ], $reservedReference);

            return [
                'message' => $payoutModel->failure_reason,
                'http_status' => 502,
            ];
        }

        $this->updateIfUnpaid($payoutModel, [
            'status' => EventPayout::STATUS_PROCESSING,
            'provider_reference' => $reference,
            'provider_transfer_code' => (string) ($transfer['transfer_code'] ?? ''),
            'failure_reason' => null,
            'transfer_fee_amount' => $this->transferFeeSchedule->feeFromProviderResponse($transfer) ?? $transferFee,
            'net_paid_amount' => $netToSend,
        ], $reservedReference);

        return ['message' => 'Payout sent.', 'transfer_code' => $transfer['transfer_code'], 'http_status' => 200];
    }

    /** @param array<string, mixed> $attributes */
    private function updateIfUnpaid(EventPayout $payout, array $attributes, ?string $expectedReference = null): void
    {
        $query = EventPayout::withoutGlobalScopes()->whereKey($payout->id)->where('status', '!=', EventPayout::STATUS_PAID);
        if ($expectedReference !== null) {
            $query->where('provider_reference', $expectedReference)->where('status', EventPayout::STATUS_PROCESSING);
        }
        $query->update($attributes);
        $payout->refresh();
    }
}
