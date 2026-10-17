<?php

declare(strict_types=1);

namespace App\Services\Giving;

use App\Models\CommunitySettings;
use App\Models\EventContribution;
use App\Models\GivingAdjustment;
use App\Models\GivingContributionLink;
use App\Models\GivingFund;
use App\Models\GivingGift;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class GivingProgress
{
    /** @return array{raised:int, left:int|null, percent:float|null, identified_givers:int, target_reached:bool} */
    public function for(GivingFund $fund): array
    {
        $gifts = GivingGift::withoutGlobalScopes()->where('tenant_id', $fund->tenant_id)->where('fund_id', $fund->id)->whereIn('status', ['completed', 'refunded'])->get(['id', 'amount', 'giver_key']);
        $links = GivingContributionLink::withoutGlobalScopes()->where('tenant_id', $fund->tenant_id)->where('fund_id', $fund->id)->get(['event_contribution_id', 'origin']);
        $contributions = EventContribution::withoutGlobalScopes()->where('tenant_id', $fund->tenant_id)->whereIn('id', $links->pluck('event_contribution_id'))->whereIn('status', [EventContribution::STATUS_COMPLETED, EventContribution::STATUS_REFUNDED])->get(['id', 'amount', 'status']);
        $adjustments = GivingAdjustment::withoutGlobalScopes()->where('tenant_id', $fund->tenant_id)->where('fund_id', $fund->id)->where('status', 'confirmed')->get(['gift_id', 'event_contribution_id', 'principal']);
        $giftReductions = $adjustments->whereNotNull('gift_id')->groupBy('gift_id')->map(fn ($rows): int => (int) $rows->sum('principal'));
        $eventReductions = $adjustments->whereNotNull('event_contribution_id')->groupBy('event_contribution_id')->map(fn ($rows): int => (int) $rows->sum('principal'));
        $raised = 0;
        $keys = [];
        foreach ($gifts as $gift) {
            $netPrincipal = max(0, $gift->amount - ($giftReductions[$gift->id] ?? 0));
            $raised += $netPrincipal;
            if ($netPrincipal > 0 && $gift->giver_key !== null) {
                $keys[$gift->giver_key] = true;
            }
        }
        foreach ($contributions as $contribution) {
            $legacyRefunded = $contribution->status === EventContribution::STATUS_REFUNDED && ! $eventReductions->has($contribution->id);
            $raised += $legacyRefunded ? 0 : max(0, $contribution->amount - ($eventReductions[$contribution->id] ?? 0));
        }
        $target = $fund->kind === 'campaign' ? $fund->target : null;

        return [
            'raised' => $raised, 'left' => $target === null ? null : max(0, $target - $raised),
            'percent' => $target === null ? null : round($raised / $target * 100, 2),
            'identified_givers' => count($keys), 'target_reached' => $target !== null && $raised >= $target,
        ];
    }

    /** Call with the fund locked before accepting a new collection or reserving checkout. */
    public function assertOpen(GivingFund $fund): void
    {
        $timezone = CommunitySettings::for($fund->tenant)->timezone;
        if ($fund->state !== 'open' || ($fund->ends_on !== null && CarbonImmutable::now($timezone)->toDateString() > $fund->ends_on->toDateString()) || ($fund->close_on_target && $this->for($fund)['target_reached'])) {
            throw ValidationException::withMessages(['fund' => 'This fund is not accepting new contributions.']);
        }
    }

    /** Existing in-flight payments may complete even after a deadline or close. */
    public function closeIfTargetReached(GivingFund $fund): void
    {
        if ($fund->state === 'open' && $fund->close_on_target && $this->for($fund)['target_reached']) {
            $fund->update(['state' => 'closed', 'audit' => [...($fund->audit ?? []), ['action' => 'target_reached', 'at' => now()->toIso8601String()]]]);
        }
    }
}
