<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enum\LedgerCategoryEnum;
use App\Models\EventPayout;
use App\Models\Tenant;
use App\Models\TenantPayoutAccount;
use App\Models\User;
use App\Services\Dues\DuesBalance;
use App\Services\Giving\GivingBalance;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CommunityPayouts
{
    public function __construct(private readonly LedgerService $ledger, private readonly DuesBalance $dues, private readonly GivingBalance $giving, private readonly PayoutSender $sender, private readonly PayoutRelease $release, private readonly TransferFeeSchedule $fees) {}

    /** @param array<string, mixed> $validated */
    public function reserve(Tenant $tenant, User $actor, LedgerCategoryEnum $category, array $validated): EventPayout
    {
        $this->authorize($tenant, $actor);
        $balance = $this->balance($category);
        $currency = mb_strtoupper((string) ($validated['currency'] ?? ''));
        $account = $this->account($tenant, (string) ($validated['payout_account_id'] ?? ''));
        $amount = (int) ($validated['amount'] ?? 0);
        if ($amount <= $this->fees->feeFor($account->type)) {
            throw ValidationException::withMessages(['amount' => 'This balance is smaller than the transfer fee, so it cannot be sent on its own. It will be paid out with the next batch.']);
        }
        $this->ledger->ensureAccountsExist($tenant, null, $currency, $category);

        return $tenant->getConnection()->transaction(function () use ($tenant, $category, $validated, $balance, $currency, $account, $amount): EventPayout {
            $balance->lockPayableAccount($tenant, $currency);
            $this->account($tenant, $account->id, true);
            $available = $balance->available($tenant, $currency);
            if ($amount > $available) {
                throw ValidationException::withMessages(['amount' => "Requested amount ({$amount}) exceeds the available {$category->value} balance ({$available})."]);
            }

            return EventPayout::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'event_id' => null, 'category' => $category,
                'payout_account_id' => $account->id, 'amount' => $amount, 'currency' => $currency,
                'status' => EventPayout::STATUS_SCHEDULED, 'scheduled_at' => $validated['scheduled_at'] ?? null, 'note' => $validated['note'] ?? null]);
        });
    }

    /** @return array<string, mixed> */
    public function send(Tenant $tenant, User $actor, EventPayout $payout): array
    {
        $this->authorize($tenant, $actor);
        $this->assertPayout($tenant, $payout);
        $balance = $this->balance($payout->category);
        $account = $this->account($tenant, (string) $payout->payout_account_id);
        $claim = $tenant->getConnection()->transaction(function () use ($tenant, $payout, $balance, $account): array {
            $balance->lockPayableAccount($tenant, $payout->currency);
            $this->account($tenant, $account->id, true);
            $locked = EventPayout::withoutGlobalScopes()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($payout->id);
            if (! in_array($locked->status, [EventPayout::STATUS_SCHEDULED, EventPayout::STATUS_FAILED], true)) {
                return ['error' => ['message' => 'Only a scheduled or failed payout can be sent.', 'http_status' => 422]];
            }
            if ($locked->status === EventPayout::STATUS_FAILED && $locked->amount > $balance->available($tenant, $payout->currency)) {
                $available = $balance->available($tenant, $payout->currency);

                return ['error' => ['message' => "This payout ({$locked->amount}) is more than the available {$payout->category->value} balance ({$available}), so it cannot be sent again.", 'http_status' => 422]];
            }
            if ($locked->amount <= $this->fees->feeFor($account->type)) {
                return ['error' => ['message' => 'This balance is smaller than the transfer fee, so it cannot be sent on its own. It will be paid out with the next batch.', 'http_status' => 422]];
            }
            $reference = 'payout_'.$locked->id.'_'.Str::random(8);
            $locked->update(['status' => EventPayout::STATUS_PROCESSING, 'provider_reference' => $reference, 'failure_reason' => null]);

            return ['payout' => $locked, 'reference' => $reference];
        });
        if (isset($claim['error'])) {
            return $claim['error'];
        }

        return $this->sender->send($claim['payout'], $payout->currency, $claim['reference']);
    }

    /** @return array<string, mixed> */
    public function finalize(Tenant $tenant, User $actor, EventPayout $payout, string $otp): array
    {
        $this->authorize($tenant, $actor);
        $this->assertPayout($tenant, $payout);
        $this->account($tenant, (string) $payout->payout_account_id);

        return $this->release->releaseWithoutLock($payout, $otp);
    }

    /** Called only after the provider's transfer has been verified. */
    public function settleTransfer(EventPayout $payout, string $reference): void
    {
        $this->assertPayout($payout->tenant, $payout);
        $balance = $this->balance($payout->category);
        $payout->getConnection()->transaction(function () use ($payout, $reference, $balance): void {
            $balance->lockPayableAccount($payout->tenant, $payout->currency);
            $locked = EventPayout::withoutGlobalScopes()->where('tenant_id', $payout->tenant_id)->lockForUpdate()->findOrFail($payout->id);
            if ($locked->provider_reference !== $reference || ! in_array($locked->status, [EventPayout::STATUS_PROCESSING, EventPayout::STATUS_AWAITING_OTP], true)) {
                return;
            }
            $fee = $locked->transfer_fee_amount ?: ($locked->requested_transfer_fee_amount ?? $this->fees->feeFor($locked->payoutAccount->type));
            $locked->update(['status' => EventPayout::STATUS_PAID, 'paid_at' => now(), 'transfer_fee_amount' => $fee, 'net_paid_amount' => $locked->net_paid_amount ?? $locked->requested_net_amount ?? $locked->amount - $fee]);
            if ($locked->category === LedgerCategoryEnum::Giving) {
                $this->ledger->recordGivingPayout($locked->tenant, $locked, $reference, $fee);
            } else {
                $this->ledger->recordDuesPayout($locked->tenant, $locked->amount, $reference, $locked, $locked->currency, $fee);
            }
        });
    }

    public function balance(LedgerCategoryEnum $category): DuesBalance|GivingBalance
    {
        return match ($category) {
            LedgerCategoryEnum::Dues => $this->dues,
            LedgerCategoryEnum::Giving => $this->giving,
            default => throw ValidationException::withMessages(['category' => 'Choose Dues or Giving.']),
        };
    }

    private function assertPayout(Tenant $tenant, EventPayout $payout): void
    {
        abort_unless($payout->tenant_id === $tenant->id && $payout->event_id === null && in_array($payout->category, [LedgerCategoryEnum::Dues, LedgerCategoryEnum::Giving], true), 404);
    }

    private function account(Tenant $tenant, string $id, bool $lock = false): TenantPayoutAccount
    {
        $query = TenantPayoutAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $account = $query->first();
        if ($account === null || ! $account->is_verified) {
            throw ValidationException::withMessages(['payout_account_id' => 'Choose a verified payout account belonging to this organisation.']);
        }

        return $account;
    }

    private function authorize(Tenant $tenant, User $actor): void
    {
        abort_unless($actor->tenant_id === $tenant->id || $tenant->users()->where('users.id', $actor->id)->exists(), 403);
        Gate::forUser($actor)->authorize('manage payouts');
    }
}
