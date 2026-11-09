<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Contracts\SettlementGateway;
use App\Exceptions\PaymentFailedException;
use App\Models\EventPayout;
use Closure;

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
        app(PayoutSubmissionGuard::class)->assertEntry($payout);
        if ($payout->category === \App\Enum\LedgerCategoryEnum::Commerce) {
            return ['released' => false, 'message' => 'Use the commerce payout flow to preserve its balance and original account checks.', 'http_status' => 422];
        }

        return $this->releaseWithoutLock($payout, $otp, function (EventPayout $claim): void {
            if ($claim->event_id === null) {
                return;
            }
            $event = \App\Models\Event::withoutGlobalScopes()->where('tenant_id', $claim->tenant_id)->lockForUpdate()->findOrFail($claim->event_id);
            $balance = app(\App\Services\Events\MemberBenefitBalance::class);
            if ($balance->applies($event) && ($event->currency !== $claim->currency || $claim->amount > $balance->available($event, $claim->id))) {
                throw \Illuminate\Validation\ValidationException::withMessages(['payout' => 'This parked transfer exceeds the funds available after payment and refund holds.']);
            }
        });
    }

    /** Claim once, then call the provider outside the lock; an uncertain release stays reserved.
     * @return array{released:bool, message:string, http_status:int}
     */
    public function releaseWithoutLock(EventPayout $payout, string $otp, ?Closure $beforeClaim = null): array
    {
        app(PayoutSubmissionGuard::class)->assertEntry($payout);
        $claim = $payout->getConnection()->transaction(function () use ($payout, $beforeClaim): array {
            if ($payout->category === \App\Enum\LedgerCategoryEnum::Commerce && $beforeClaim === null) {
                return ['error' => ['released' => false, 'message' => 'Commerce release requires its balance guard.', 'http_status' => 422]];
            }
            if ($beforeClaim !== null) {
                $beforeClaim($payout);
            }
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
                $message = ($locked->category === \App\Enum\LedgerCategoryEnum::Commerce || \App\Models\ScheduledPayoutProposal::query()->where('payout_id', $locked->id)->exists()) ? 'The one-time code was not accepted. Check the original transfer status before retrying.' : 'The one-time code was not accepted: '.$exception->providerMessage();
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
            $message = $locked->category === \App\Enum\LedgerCategoryEnum::Commerce ? 'The original transfer still needs reconciliation before release.' : 'The payment provider did not release the transfer (status: '.$status.').';
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
