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
 * Shared by event payouts and dues payouts. The caller owns the surrounding
 * DB::transaction, the row lock on the payout and the check that the payout is
 * in a sendable state: locking the row for the whole check-send-write sequence
 * is what stops two concurrent sends from both firing a live transfer, since
 * each send deliberately uses a fresh reference that the provider's own
 * duplicate protection cannot catch.
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
        $account = $payoutModel->payoutAccount;

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
            $transfer = $this->settlementGateway->initiateTransfer($account->recipient_code, $netToSend, $currency, $reference);
        } catch (PaymentFailedException $e) {
            if ($payoutModel->fresh()->status === EventPayout::STATUS_PAID) {
                return ['message' => 'Payout confirmed.', 'http_status' => 200];
            }

            return ['message' => 'Could not send payout: '.$e->providerMessage(), 'http_status' => 502];
        }

        if ($payoutModel->fresh()->status === EventPayout::STATUS_PAID) {
            return ['message' => 'Payout confirmed.', 'http_status' => 200];
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
             * Without a transfer code there is nothing to release, and a
             * parked payout cannot be re-sent — it would strand here. Fail
             * it instead, which keeps it re-sendable.
             */
            if ($transferCode === '') {
                $this->updateIfUnpaid($payoutModel, [
                    'status' => EventPayout::STATUS_FAILED,
                    'failure_reason' => 'The payment provider asked for a one-time code but returned no transfer to release. Send this payout again.',
                ]);

                return ['message' => $payoutModel->failure_reason, 'http_status' => 502];
            }

            $this->updateIfUnpaid($payoutModel, [
                'status' => EventPayout::STATUS_AWAITING_OTP,
                'provider_reference' => $reference,
                'provider_transfer_code' => $transferCode,
                'failure_reason' => null,
            ]);

            return [
                'message' => 'Paystack sent a one-time code to the account owner. Enter it to release this payout.',
                'status' => EventPayout::STATUS_AWAITING_OTP,
                'http_status' => 200,
            ];
        }

        if (! in_array($transferStatus, ['success', 'pending', 'queued'], true)) {
            $this->updateIfUnpaid($payoutModel, [
                'status' => EventPayout::STATUS_FAILED,
                'failure_reason' => "The payment provider did not release the transfer (status: {$transferStatus}).",
            ]);

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
        ]);

        return ['message' => 'Payout sent.', 'transfer_code' => $transfer['transfer_code'], 'http_status' => 200];
    }

    /** @param array<string, mixed> $attributes */
    private function updateIfUnpaid(EventPayout $payout, array $attributes): void
    {
        EventPayout::withoutGlobalScopes()->whereKey($payout->id)->where('status', '!=', EventPayout::STATUS_PAID)->update($attributes);
        $payout->refresh();
    }
}
