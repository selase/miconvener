<?php

declare(strict_types=1);

namespace App\Services\Dues;

use App\Enum\DuesChargeStatusEnum;
use App\Enum\MemberStatusEnum;
use App\Models\CommunitySettings;
use App\Models\DuesCharge;
use App\Models\Member;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Raising and settling the charges a member owes. Settling a charge by hand
 * (waive, void, record offline) never touches the ledger: only money that went
 * through the platform is posted there.
 */
final class DuesCharges
{
    /**
     * The charge for the period containing $periodStart. Calendar periods use
     * the organisation's configured weekday and financial-year anchors. The amount is
     * frozen from the member's effective amount now, and a zero amount (a
     * waiver) is created already waived. Asking again for the same period
     * returns the charge that already exists.
     *
     * @throws DomainException When the member has left, or the amount is
     *                         above zero but below the minimum charge.
     */
    public function createFor(Member $member, CarbonImmutable $periodStart, ?CommunitySettings $settings = null): DuesCharge
    {
        return DB::connection('landlord')->transaction(function () use ($member, $periodStart, $settings): DuesCharge {
            $locked = Member::withoutGlobalScopes()->where('tenant_id', $member->tenant_id)
                ->with('membershipType')->lockForUpdate()->findOrFail($member->id);

            return $this->createLocked($locked, $periodStart, $settings);
        });
    }

    /**
     * @throws DomainException When the charge is not open.
     */
    public function waive(DuesCharge $charge, ?string $note): DuesCharge
    {
        return $this->closeOpen($charge, [
            'status' => DuesChargeStatusEnum::Waived,
            'note' => $note,
        ]);
    }

    /**
     * @throws DomainException When the charge is not open.
     */
    public function void(DuesCharge $charge, ?string $note): DuesCharge
    {
        return $this->closeOpen($charge, [
            'status' => DuesChargeStatusEnum::Void,
            'note' => $note,
        ]);
    }

    /**
     * Marks the charge paid by cash or transfer outside the platform, so no
     * ledger posting is made and no commission is taken.
     *
     * @throws DomainException When the charge is not open.
     */
    public function recordOffline(DuesCharge $charge, int $userId, ?string $note): DuesCharge
    {
        return $charge->getConnection()->transaction(function () use ($charge, $userId, $note): DuesCharge {
            $paid = $this->closeOpen($charge, ['status' => DuesChargeStatusEnum::Paid, 'payment_method' => 'offline', 'paid_at' => now(), 'recorded_by' => $userId, 'note' => $note]);
            $member = Member::withoutGlobalScopes()->where('tenant_id', $paid->tenant_id)->findOrFail($paid->member_id);
            $tenant = \App\Models\Tenant::query()->findOrFail($paid->tenant_id);
            app(\App\Services\Community\InboxNotices::class)->receipt($tenant, $member, $paid->id, new \Illuminate\Database\Eloquent\Collection([$paid]), CarbonImmutable::now());

            return $paid;
        });
    }

    /** Cancel unpaid advance periods when eligibility ends, preserving paid history. */
    public function cancelAdvanceFor(Member $member): void
    {
        DB::connection('landlord')->transaction(function () use ($member): void {
            $locked = Member::withoutGlobalScopes()->where('tenant_id', $member->tenant_id)->lockForUpdate()->findOrFail($member->id);
            $date = match ($locked->status) {
                MemberStatusEnum::Exited => $locked->exited_on,
                MemberStatusEnum::Lapsed => $locked->lapsed_on,
                default => null,
            };
            if ($date === null) {
                return;
            }
            $note = "Dues stopped: membership {$locked->status->value} on {$date->toDateString()}.";
            $charges = DuesCharge::withoutGlobalScopes()->where('tenant_id', $locked->tenant_id)->where('member_id', $locked->id)
                ->where('status', DuesChargeStatusEnum::Open)->whereDate('period_start', '>=', $date->toDateString())
                ->orderBy('period_start')->orderBy('id')->lockForUpdate()->get();
            foreach ($charges as $charge) {
                $charge->update(['status' => DuesChargeStatusEnum::Void, 'note' => filled($charge->note) ? "{$charge->note}\n{$note}" : $note]);
            }
        });
    }

    private function createLocked(Member $member, CarbonImmutable $periodStart, ?CommunitySettings $settings): DuesCharge
    {
        if ($member->status === MemberStatusEnum::Exited) {
            throw new DomainException('This member has left and cannot be charged.');
        }
        if ($member->status !== MemberStatusEnum::Active) {
            throw new DomainException('Only active members can be charged.');
        }

        $class = $member->membershipType;
        if (! $class->is_active) {
            throw new DomainException('This membership type is inactive.');
        }

        if (! $class->hasDues() || $class->period === null) {
            throw new DomainException('This membership type has no dues.');
        }

        $settings ??= CommunitySettings::for($member->tenant);
        $start = $class->period->periodStartFor($periodStart, $settings);
        $amount = (int) $member->effectiveAmount();

        if ($amount > 0 && $amount < DuesCharge::MINIMUM_AMOUNT) {
            throw new DomainException('A charge must be at least '.DuesCharge::MINIMUM_AMOUNT.' minor units ('.number_format(DuesCharge::MINIMUM_AMOUNT / 100, 2).').');
        }

        return DuesCharge::query()->firstOrCreate(
            ['member_id' => $member->id, 'period_start' => $start->toDateString()],
            [
                'tenant_id' => $member->tenant_id,
                'membership_type_id' => $class->id,
                'period_end' => $class->period->periodEnd($start, $settings)->toDateString(),
                'due_on' => $start->toDateString(),
                'amount' => $amount,
                'currency' => $class->currency,
                'status' => $amount === 0 ? DuesChargeStatusEnum::Waived : DuesChargeStatusEnum::Open,
            ],
        );
    }

    /**
     * Applies $changes to the charge under a row lock, so two admins acting on
     * the same charge cannot both succeed.
     *
     * @param  array<string, mixed>  $changes
     *
     * @throws DomainException When the charge is not open.
     */
    private function closeOpen(DuesCharge $charge, array $changes): DuesCharge
    {
        return DB::connection($charge->getConnectionName())->transaction(function () use ($charge, $changes): DuesCharge {
            $locked = DuesCharge::query()->whereKey($charge->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== DuesChargeStatusEnum::Open) {
                throw new DomainException('Only an open charge can be changed.');
            }

            if (($changes['note'] ?? null) === null) {
                unset($changes['note']);
            }

            $locked->update($changes);

            return $locked;
        });
    }
}
