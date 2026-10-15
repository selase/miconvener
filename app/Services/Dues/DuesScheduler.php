<?php

declare(strict_types=1);

namespace App\Services\Dues;

use App\Enum\DuesChargeStatusEnum;
use App\Enum\MemberStatusEnum;
use App\Enum\TenantStatusEnum;
use App\Models\CommunitySettings;
use App\Models\Member;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class DuesScheduler
{
    private const int MAX_PERIODS = 400;

    public function __construct(private readonly DuesCharges $charges, private readonly TenantContext $context) {}

    public function generateFor(Tenant $tenant, CarbonImmutable $localToday): int
    {
        if (! $tenant->communityEnabled() || $tenant->status !== TenantStatusEnum::ACTIVE) {
            return 0;
        }

        $previous = $this->context->getTenant();
        $previousId = $this->context->activeTenantId();
        $this->context->setTenant($tenant);

        try {
            $settings = CommunitySettings::for($tenant);
            $created = 0;
            Member::query()->where('tenant_id', $tenant->id)->where('status', MemberStatusEnum::Active)
                ->orderBy('id')->chunkById(100, function ($members) use ($localToday, $settings, &$created): void {
                    foreach ($members as $member) {
                        $created += DB::connection('landlord')->transaction(function () use ($member, $localToday, $settings): int {
                            $locked = Member::query()->with('membershipType')->lockForUpdate()->findOrFail($member->id);

                            return $this->generateMember($locked, $localToday, $settings);
                        });
                    }
                });

            return $created;
        } finally {
            if ($previous !== null) {
                $this->context->setTenant($previous);
            } else {
                $this->context->clear();
            }
            $this->context->setActiveTenantId($previousId);
        }
    }

    private function generateMember(Member $member, CarbonImmutable $today, CommunitySettings $settings): int
    {
        $type = $member->membershipType;
        if ($member->status !== MemberStatusEnum::Active || ! $type->is_active || ! $type->hasDues() || $type->period === null) {
            return 0;
        }

        $period = $type->period;
        $since = $member->active_since ?? $member->joined_on ?? $member->created_at;
        $first = $period->periodStartFor(CarbonImmutable::parse($since->toDateString(), $settings->timezone), $settings);
        if (! $settings->charge_current_period_on_join || $member->rejoined_on !== null || $member->lapsed_on !== null) {
            $first = $period->nextStart($first, $settings);
        }
        $cursor = $period->periodStartFor($today->addDays($settings->generate_days_ahead), $settings);
        $starts = [];
        while ($cursor->gte($first) && count($starts) < self::MAX_PERIODS) {
            $starts[] = $cursor;
            $cursor = $period->periodStartFor($cursor->subDay(), $settings);
        }
        if ($cursor->gte($first)) {
            Log::warning('dues.generation.window_capped', ['tenant_id' => $member->tenant_id, 'member_id' => $member->id]);
        }

        $existingStarts = [];
        $occupied = [];
        foreach ($member->charges()->whereDate('period_end', '>=', $first->toDateString())->get() as $charge) {
            $startDate = $charge->period_start->toDateString();
            $existingStarts[$startDate] = true;
            if ($charge->status !== DuesChargeStatusEnum::Void) {
                $occupied[] = ['start' => $startDate, 'end' => $charge->period_end->toDateString()];
            }
        }

        $created = 0;
        foreach (array_reverse($starts) as $start) {
            $end = $period->periodEnd($start, $settings);
            if (isset($existingStarts[$start->toDateString()])) {
                continue;
            }
            foreach ($occupied as $interval) {
                if ($interval['start'] <= $end->toDateString() && $interval['end'] >= $start->toDateString()) {
                    continue 2;
                }
            }
            $this->charges->createFor($member, $start, $settings);
            $created++;
        }

        return $created;
    }
}
