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
    /**
     * Transfer statuses from which the money will never move.
     *
     * @var list<string>
     */
    public const array DEAD_TRANSFER_STATUSES = ['failed', 'reversed', 'abandoned'];

    public const string OTP_EXPIRED_REASON = 'The one-time code expired before it was entered. Send the payout again.';

    public function __construct(
        private readonly SettlementGateway $settlementGateway,
        private readonly TransferFeeSchedule $transferFeeSchedule,
    ) {}

    /**
     * @return array{released: bool, message: string, http_status: int}
     */
    public function release(EventPayout $payout, string $otp): array
    {
        return DB::connection('landlord')->transaction(function () use ($payout, $otp): array {
            // Same lock as sending: two submissions of the same code must not
            // both release the transfer.
            $payoutModel = EventPayout::query()->whereKey($payout->getKey())->lockForUpdate()->firstOrFail();

            if ($payoutModel->status !== EventPayout::STATUS_AWAITING_OTP) {
                return ['released' => false, 'message' => 'This payout is not waiting for a one-time code.', 'http_status' => 422];
            }

            if (! $payoutModel->provider_transfer_code) {
                return ['released' => false, 'message' => 'This payout has no transfer to release. Send it again.', 'http_status' => 422];
            }

            if ($payoutModel->event_id !== null) {
                $event = \App\Models\Event::withoutGlobalScopes()->where('tenant_id', $payoutModel->tenant_id)->lockForUpdate()->findOrFail($payoutModel->event_id);
                $balance = app(\App\Services\Events\MemberBenefitBalance::class);
                if ($balance->applies($event) && ($event->currency !== $payoutModel->currency || $payoutModel->amount > $balance->available($event, $payoutModel->id))) {
                    return ['released' => false, 'message' => 'This parked transfer exceeds the funds available after payment and refund holds. Reconcile the holds before releasing it.', 'http_status' => 422];
                }
            }

            try {
                $transfer = $this->settlementGateway->finalizeTransfer($payoutModel->provider_transfer_code, $otp);
            } catch (PaymentFailedException $e) {
                /*
                 * Paystack abandons a transfer whose code was not entered in
                 * time and then refuses every code. Such a payout is failed so
                 * it can be sent again and stops holding the balance.
                 */
                if ($this->transferIsDead($payoutModel)) {
                    $payoutModel->update(['status' => EventPayout::STATUS_FAILED, 'failure_reason' => self::OTP_EXPIRED_REASON]);

                    return ['released' => false, 'message' => self::OTP_EXPIRED_REASON, 'http_status' => 422];
                }

                /*
                 * A rejected code is not a failed payout — the transfer is
                 * still parked and the code can be entered again.
                 */
                $payoutModel->update(['failure_reason' => 'The one-time code was not accepted: '.$e->providerMessage()]);

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

    /** Claim once, then call the provider outside the lock; an uncertain release stays reserved.
     * @return array{released:bool, message:string, http_status:int}
     */
    public function releaseWithoutLock(EventPayout $payout, string $otp): array
    {
        $claim = $payout->getConnection()->transaction(function () use ($payout): array {
            $locked = EventPayout::withoutGlobalScopes()->lockForUpdate()->findOrFail($payout->id);
            if ($locked->status !== EventPayout::STATUS_AWAITING_OTP || ! $locked->provider_transfer_code) {
                return ['error' => ['released' => false, 'message' => 'This payout is not waiting for a one-time code.', 'http_status' => 422]];
            }
            $locked->update(['status' => EventPayout::STATUS_PROCESSING]);

            return ['payout' => $locked];
        });
        if (isset($claim['error'])) {
            return $claim['error'];
        }
        $locked = $claim['payout'];
        try {
            $transfer = $this->settlementGateway->finalizeTransfer($locked->provider_transfer_code, $otp);
        } catch (PaymentFailedException $exception) {
            if ($locked->fresh()->status === EventPayout::STATUS_PAID) {
                return ['released' => true, 'message' => 'Payout confirmed.', 'http_status' => 200];
            }
            if ($this->transferIsDead($locked)) {
                $this->changeProcessing($locked, ['status' => EventPayout::STATUS_FAILED, 'failure_reason' => self::OTP_EXPIRED_REASON]);

                return ['released' => false, 'message' => self::OTP_EXPIRED_REASON, 'http_status' => 422];
            }
            $previous = $exception->getPrevious();
            if ($previous instanceof \Illuminate\Http\Client\RequestException && $previous->response->status() >= 400 && $previous->response->status() < 500) {
                $message = 'The one-time code was not accepted: '.$exception->providerMessage();
                $this->changeProcessing($locked, ['status' => EventPayout::STATUS_AWAITING_OTP, 'failure_reason' => $message]);

                return ['released' => false, 'message' => $message, 'http_status' => 422];
            }
            $message = 'The release outcome is uncertain. Check its provider status before trying again.';
            $this->changeProcessing($locked, ['failure_reason' => $message]);

            return ['released' => false, 'message' => $message, 'http_status' => 502];
        }
        if ($locked->fresh()->status === EventPayout::STATUS_PAID) {
            return ['released' => true, 'message' => 'Payout confirmed.', 'http_status' => 200];
        }
        $status = $transfer['status'];
        if (! in_array($status, ['success', 'pending', 'queued'], true)) {
            $message = 'The payment provider did not release the transfer (status: '.$status.').';
            $this->changeProcessing($locked, ['status' => in_array($status, self::DEAD_TRANSFER_STATUSES, true) ? EventPayout::STATUS_FAILED : EventPayout::STATUS_AWAITING_OTP, 'failure_reason' => $message]);

            return ['released' => false, 'message' => $message, 'http_status' => 502];
        }
        $scheduledFee = $locked->requested_transfer_fee_amount ?? $this->transferFeeSchedule->feeFor($locked->payoutAccount->type);
        $this->changeProcessing($locked, ['failure_reason' => null, 'transfer_fee_amount' => $this->transferFeeSchedule->feeFromProviderResponse($transfer) ?? $scheduledFee,
            'net_paid_amount' => $locked->requested_net_amount ?? $locked->amount - $scheduledFee]);

        return ['released' => true, 'message' => 'Payout released.', 'http_status' => 200];
    }

    /** @param array<string, mixed> $attributes */
    private function changeProcessing(EventPayout $payout, array $attributes): void
    {
        EventPayout::withoutGlobalScopes()->whereKey($payout->id)->where('provider_reference', $payout->provider_reference)->where('provider_transfer_code', $payout->provider_transfer_code)->where('status', EventPayout::STATUS_PROCESSING)->update($attributes);
    }

    /**
     * Whether the provider says the payout's transfer is dead. A transfer
     * that cannot be checked is not assumed dead: an outage must never fail
     * a payout whose money may still move.
     */
    private function transferIsDead(EventPayout $payout): bool
    {
        if (blank($payout->provider_reference)) {
            return false;
        }

        try {
            $status = $this->settlementGateway->verifyTransfer((string) $payout->provider_reference)['status'];
        } catch (PaymentFailedException) {
            return false;
        }

        return in_array($status, self::DEAD_TRANSFER_STATUSES, true);
    }
}
