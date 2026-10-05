<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Contracts\SettlementGateway;
use App\Exceptions\PaymentFailedException;
use App\Models\EventPayout;
use Illuminate\Support\Facades\DB;

/**
 * Release a payout Paystack is holding for a one-time code.
 *
 * Payouts go through MiConvener's own Paystack account, so with transfer OTP
 * switched on the code reaches MiConvener's phone. It can be entered by the
 * organiser (if we pass it on) or by a superadmin from the console; both go
 * through here.
 *
 * The transfer fee is recorded here rather than at initiation: Paystack
 * charges it when the money actually moves, so a code that is never entered
 * costs nothing and must not be billed to the organizer.
 */
final class PayoutRelease
{
    public function __construct(
        private readonly SettlementGateway $settlementGateway,
        private readonly TransferFeeSchedule $transferFeeSchedule,
    ) {}

    /**
     * @return array{released: bool, message: string, http_status: int}
     */
    public function release(EventPayout $payout, string $otp): array
    {
        return DB::transaction(function () use ($payout, $otp): array {
            // Same lock as sending: two submissions of the same code must not
            // both release the transfer.
            $payoutModel = EventPayout::query()->whereKey($payout->getKey())->lockForUpdate()->firstOrFail();

            if ($payoutModel->status !== EventPayout::STATUS_AWAITING_OTP) {
                return ['released' => false, 'message' => 'This payout is not waiting for a one-time code.', 'http_status' => 422];
            }

            if (! $payoutModel->provider_transfer_code) {
                return ['released' => false, 'message' => 'This payout has no transfer to release. Send it again.', 'http_status' => 422];
            }

            try {
                $transfer = $this->settlementGateway->finalizeTransfer($payoutModel->provider_transfer_code, $otp);
            } catch (PaymentFailedException $e) {
                /*
                 * A rejected code is not a failed payout — the transfer is
                 * still parked and the code can be entered again.
                 */
                $payoutModel->update(['failure_reason' => 'The one-time code was not accepted: '.$e->getMessage()]);

                return ['released' => false, 'message' => (string) $payoutModel->failure_reason, 'http_status' => 422];
            }

            $transferStatus = $transfer['status'];

            if (! in_array($transferStatus, ['success', 'pending', 'queued'], true)) {
                $payoutModel->update([
                    'status' => EventPayout::STATUS_FAILED,
                    'failure_reason' => "The payment provider did not release the transfer (status: {$transferStatus}).",
                ]);

                return ['released' => false, 'message' => (string) $payoutModel->failure_reason, 'http_status' => 502];
            }

            $account = $payoutModel->payoutAccount;
            $transferFee = $this->transferFeeSchedule->feeFor($account->type);

            /*
             * net_paid_amount follows the scheduled fee because that is what
             * the transfer was actually requested for, while transfer_fee_amount
             * records what the provider says it charged. They normally agree;
             * when they do not, the provider has changed its pricing and the
             * gap is worth seeing rather than papering over.
             */
            $payoutModel->update([
                'status' => EventPayout::STATUS_PROCESSING,
                'failure_reason' => null,
                'transfer_fee_amount' => $this->transferFeeSchedule->feeFromProviderResponse($transfer) ?? $transferFee,
                'net_paid_amount' => $payoutModel->amount - $transferFee,
            ]);

            return ['released' => true, 'message' => 'Payout released.', 'http_status' => 200];
        });
    }
}
