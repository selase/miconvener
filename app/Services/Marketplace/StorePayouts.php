<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Contracts\SettlementGateway;
use App\Enum\LedgerCategoryEnum;
use App\Models\EventPayout;
use App\Models\Tenant;
use App\Models\TenantPayoutAccount;
use App\Models\User;
use App\Services\Finance\LedgerService;
use App\Services\Finance\PayoutRelease;
use App\Services\Finance\PayoutSender;
use App\Services\Finance\TransferFeeSchedule;
use App\Services\Giving\GivingGateways;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Telescope\Telescope;
use Throwable;

final class StorePayouts
{
    public function __construct(private readonly CommerceInventoryLocks $locks, private readonly StoreBalance $balance, private readonly StoreCommercialTerms $terms, private readonly PayoutSender $sender, private readonly PayoutRelease $release, private readonly TransferFeeSchedule $fees, private readonly SettlementGateway $gateway, private readonly LedgerService $ledger) {}

    /** @param array<string, mixed> $validated */
    public function reserve(Tenant $seller, User $actor, array $validated): EventPayout
    {
        $this->authorize($seller, $actor);
        $amount = $validated['amount'] ?? null;
        $key = $validated['operation_key'] ?? null;
        if (! is_int($amount) || $amount < 1 || $amount > 9007199254740991 || ($validated['currency'] ?? '') !== 'GHS' || ! is_string($key) || ! Str::isUuid($key)) {
            $this->refuse('Use a positive GHS amount in minor units and a valid payout operation key.');
        }

        return $seller->getConnection()->transaction(function () use ($seller, $actor, $validated, $amount, $key): EventPayout {
            $seller = $this->locks->tenant($seller->id);
            $this->authorize($seller, $actor);
            $prior = EventPayout::withoutGlobalScopes()->where('tenant_id', $seller->id)->where('commerce_operation_key', $key)->first();
            if ($prior !== null) {
                if ($prior->amount !== $amount || $prior->payout_account_id !== ($validated['payout_account_id'] ?? null)) {
                    $this->refuse('This payout operation key was used for another request.');
                }

                return $prior;
            }
            $agreement = $this->terms->resolve($seller);
            $account = $this->account($seller, (string) ($validated['payout_account_id'] ?? ''), true);
            if ($amount <= $this->fees->feeFor($account->type) || $amount > $this->balance->available($seller)) {
                $this->refuse('The payout exceeds available commerce funds after holds, or cannot cover its transfer fee.');
            }

            return EventPayout::withoutGlobalScopes()->create(['tenant_id' => $seller->id, 'event_id' => null, 'category' => LedgerCategoryEnum::Commerce,
                'payout_account_id' => $account->id, 'amount' => $amount, 'currency' => 'GHS', 'status' => EventPayout::STATUS_SCHEDULED,
                'commerce_operation_key' => $key, 'commerce_account_fingerprint' => $agreement->account_fingerprint,
                'commerce_recipient_fingerprint' => $this->recipientFingerprint($account), 'commerce_recipient_display' => mb_substr(($account->label ?? 'Verified payout account').' · '.$account->maskedAccountNumber(), 0, 250), 'note' => mb_substr((string) ($validated['note'] ?? ''), 0, 500)]);
        });
    }

    /** @return array<string, mixed> */
    public function send(EventPayout $payout): array
    {
        $this->assertPayout($payout);
        $claimed = $payout->getConnection()->transaction(function () use ($payout): ?EventPayout {
            $seller = $this->locks->tenant($payout->tenant_id);
            $current = EventPayout::withoutGlobalScopes()->where('tenant_id', $seller->id)->lockForUpdate()->findOrFail($payout->id);
            if ($current->status !== EventPayout::STATUS_SCHEDULED || $current->provider_reference !== null) {
                return null;
            }
            $account = $this->originalAccount($current);
            if ($current->amount > $this->balance->available($seller, excludePayout: $current->id)) {
                $this->refuse('Refunds or other holds prevent sending this payout.');
            }
            $current->update(['status' => EventPayout::STATUS_PROCESSING, 'provider_reference' => 'store-payout-'.Str::uuid(), 'failure_reason' => null]);

            $current->setRelation('payoutAccount', $account);

            return $current;
        });
        if ($claimed === null) {
            return ['message' => 'This payout is already submitted, parked or complete. Reconcile its original reference.', 'http_status' => 422];
        }

        return Telescope::withoutRecording(fn () => $this->sender->send($claimed, 'GHS', $claimed->provider_reference));
    }

    /** @return array<string, mixed> */
    public function finalize(EventPayout $payout, string $otp): array
    {
        $this->assertPayout($payout);
        if (! preg_match('/^[0-9]{4,12}$/D', $otp)) {
            $this->refuse('Enter the one-time payout code.');
        }

        return Telescope::withoutRecording(fn () => $this->release->releaseWithoutLock($payout, $otp, function (EventPayout $claim): void {
            $seller = $this->locks->tenant($claim->tenant_id);
            $current = EventPayout::withoutGlobalScopes()->lockForUpdate()->findOrFail($claim->id);
            $this->originalPlatformAccount($current);
            if ($current->amount > $this->balance->available($seller, excludePayout: $current->id)) {
                $this->refuse('Reconcile refund and balance holds before releasing this payout.');
            }
        }));
    }

    public function reconcile(EventPayout $payout): EventPayout
    {
        $this->assertPayout($payout);
        $payout = $payout->fresh();
        if ($payout->status === EventPayout::STATUS_PAID || ! filled($payout->provider_reference)) {
            return $payout;
        }
        $this->originalPlatformAccount($payout);
        try {
            $data = Telescope::withoutRecording(fn () => $this->gateway->verifyTransfer($payout->provider_reference));
        } catch (Throwable) {
            EventPayout::withoutGlobalScopes()->whereKey($payout->id)->where('status', '<>', EventPayout::STATUS_PAID)->update(['failure_reason' => 'Original transfer verification is unavailable; do not resend.']);

            return $payout->fresh();
        }

        return $payout->getConnection()->transaction(function () use ($payout, $data): EventPayout {
            $this->locks->tenant($payout->tenant_id);
            $current = EventPayout::withoutGlobalScopes()->lockForUpdate()->findOrFail($payout->id);
            if ($current->status === EventPayout::STATUS_PAID) {
                return $current;
            }
            $net = $current->requested_net_amount;
            $fee = $data['fee'] ?? $current->requested_transfer_fee_amount;
            if (filled($current->provider_transfer_code) && filled($data['transfer_code'] ?? null) && $current->provider_transfer_code !== $data['transfer_code']) {
                $current->update(['failure_reason' => 'The original transfer code does not match verification; review required.']);

                return $current;
            }
            if (($data['reference'] ?? null) !== $current->provider_reference || ! is_int($data['amount'] ?? null) || $data['amount'] !== $net
                || mb_strtoupper((string) ($data['currency'] ?? '')) !== 'GHS') {
                $current->update(['failure_reason' => 'Original transfer identity or amount needs review; do not resend.']);

                return $current;
            }
            if (($data['status']) === 'success') {
                if (! is_int($fee) || $fee < 0 || $net + $fee !== $current->amount) {
                    $current->update(['failure_reason' => 'Provider confirmed the transfer; fee accounting needs review.']);

                    return $current;
                }
                $current->update(['transfer_fee_amount' => $fee, 'net_paid_amount' => $net]);
                $this->ledger->recordStorePayout($current, $current->provider_reference);
                $current->update(['status' => EventPayout::STATUS_PAID, 'paid_at' => now(), 'failure_reason' => null]);
            } elseif (in_array($data['status'], PayoutRelease::DEAD_TRANSFER_STATUSES, true)) {
                $current->update(['status' => EventPayout::STATUS_FAILED, 'failure_reason' => 'The original transfer is verified terminal. A replacement requires a new payout request.']);
            } elseif (($data['status']) === 'otp' && filled($data['transfer_code'] ?? null)) {
                $current->update(['status' => EventPayout::STATUS_AWAITING_OTP, 'provider_transfer_code' => $data['transfer_code']]);
            }

            return $current;
        });
    }

    private function originalPlatformAccount(EventPayout $payout): void
    {
        if (! filled(config('services.settlement.paystack.secret_key')) || ! is_string($payout->commerce_account_fingerprint)
            || ! hash_equals($payout->commerce_account_fingerprint, hash('sha256', app(GivingGateways::class)->platformIdentity()))) {
            $this->refuse('The original payout account needs review.');
        }
    }

    private function originalAccount(EventPayout $payout): TenantPayoutAccount
    {
        $this->originalPlatformAccount($payout);
        $account = $this->account($payout->tenant, (string) $payout->payout_account_id);
        if (! is_string($payout->commerce_recipient_fingerprint) || ! hash_equals($payout->commerce_recipient_fingerprint, $this->recipientFingerprint($account))) {
            $this->refuse('The payout destination changed; review the original request before continuing.');
        }

        return $account;
    }

    private function account(Tenant $seller, string $id, bool $lock = false): TenantPayoutAccount
    {
        $query = TenantPayoutAccount::withoutGlobalScopes()->where('tenant_id', $seller->id)->whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $account = $query->first();
        if ($account === null || ! $account->is_verified) {
            $this->refuse('Choose a verified payout account belonging to this organisation.');
        }

        return $account;
    }

    private function recipientFingerprint(TenantPayoutAccount $account): string
    {
        return hash_hmac('sha256', json_encode([$account->tenant_id, $account->id, $account->type, $account->bank_code, $account->account_number_encrypted], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function authorize(Tenant $seller, User $actor): void
    {
        if (! User::query()->whereKey($actor->id)->where('tenant_id', $seller->id)->exists() && ! $actor->tenants()->whereKey($seller->id)->exists()) {
            throw new AuthorizationException;
        }
        $team = getPermissionsTeamId();
        try {
            setPermissionsTeamId($seller->id);
            Gate::forUser($actor->fresh())->authorize('manage store finance');
        } finally {
            setPermissionsTeamId($team);
        }
    }

    private function assertPayout(EventPayout $payout): void
    {
        abort_unless($payout->category === LedgerCategoryEnum::Commerce && $payout->event_id === null && $payout->currency === 'GHS', 404);
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['payout' => $message]);
    }
}
