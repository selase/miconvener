<?php

declare(strict_types=1);

namespace App\Services\Dues;

use App\Enum\DuesChargeStatusEnum;
use App\Enum\MemberStatusEnum;
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
     * The charge for the period containing $periodStart. Every period is
     * anchored to the first of the month, so a start on the 29th to 31st is
     * moved back to day 1 before the period end is worked out. The amount is
     * frozen from the member's effective amount now, and a zero amount (a
     * waiver) is created already waived. Asking again for the same period
     * returns the charge that already exists.
     *
     * @throws DomainException When the member has left, or the amount is
     *                         above zero but below the minimum charge.
     */
    public function createFor(Member $member, CarbonImmutable $periodStart): DuesCharge
    {
        if ($member->status === MemberStatusEnum::Exited) {
            throw new DomainException('This member has left and cannot be charged.');
        }

        $class = $member->membershipType;

        if (! $class->hasDues() || $class->period === null) {
            throw new DomainException('This membership type has no dues.');
        }

        $start = $periodStart->startOfMonth();
        $amount = (int) $member->effectiveAmount();

        if ($amount > 0 && $amount < DuesCharge::MINIMUM_AMOUNT) {
            throw new DomainException('A charge must be at least '.DuesCharge::MINIMUM_AMOUNT.' minor units ('.number_format(DuesCharge::MINIMUM_AMOUNT / 100, 2).').');
        }

        return DuesCharge::query()->firstOrCreate(
            ['member_id' => $member->id, 'period_start' => $start->toDateString()],
            [
                'tenant_id' => $member->tenant_id,
                'membership_type_id' => $class->id,
                'period_end' => $class->period->periodEnd($start)->toDateString(),
                'due_on' => $start->toDateString(),
                'amount' => $amount,
                'currency' => $class->currency,
                'status' => $amount === 0 ? DuesChargeStatusEnum::Waived : DuesChargeStatusEnum::Open,
            ],
        );
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
        return $this->closeOpen($charge, [
            'status' => DuesChargeStatusEnum::Paid,
            'payment_method' => 'offline',
            'paid_at' => now(),
            'recorded_by' => $userId,
            'note' => $note,
        ]);
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
