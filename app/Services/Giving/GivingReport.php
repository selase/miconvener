<?php

declare(strict_types=1);

namespace App\Services\Giving;

use App\Models\EventContribution;
use App\Models\GivingAdjustment;
use App\Models\GivingContributionLink;
use App\Models\GivingGift;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

final class GivingReport
{
    /** @return array<string,array<string,int>> */
    public function summary(Tenant $tenant, CarbonImmutable $from, CarbonImmutable $to, ?string $fund = null, ?string $currency = null): array
    {
        $rows = [];
        $empty = ['collected_online' => 0, 'recorded_cash' => 0, 'refunds' => 0, 'corrections' => 0, 'net_online' => 0, 'available' => 0];
        $gifts = GivingGift::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereIn('status', ['completed', 'refunded'])->when($fund, fn ($q) => $q->where('fund_id', $fund))->when($currency, fn ($q) => $q->where('currency', $currency))->where(fn ($q) => $q->whereBetween('collected_at', [$from, $to])->orWhere(fn ($q) => $q->whereNull('collected_at')->whereBetween('paid_at', [$from, $to])))->get();
        foreach ($gifts as $gift) {
            $rows[$gift->currency] ??= $empty;
            $rows[$gift->currency][$gift->source === 'online' ? 'collected_online' : 'recorded_cash'] += $gift->amount;
        }
        $links = GivingContributionLink::withoutGlobalScopes()->where('tenant_id', $tenant->id)->when($fund, fn ($q) => $q->where('fund_id', $fund))->pluck('event_contribution_id');
        $events = EventContribution::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereIn('id', $links)->whereIn('status', ['completed', 'refunded'])->whereBetween('paid_at', [$from, $to])->when($currency, fn ($q) => $q->where('currency', $currency))->get();
        foreach ($events as $event) {
            $rows[$event->currency] ??= $empty;
            $rows[$event->currency]['collected_online'] += $event->amount;
        }
        $adjustments = GivingAdjustment::withoutGlobalScopes()->with('fund')->where('tenant_id', $tenant->id)->where('status', 'confirmed')->whereBetween('confirmed_at', [$from, $to])->when($fund, fn ($q) => $q->where('fund_id', $fund))->get();
        foreach ($adjustments as $adjustment) {
            $code = $adjustment->fund->currency;
            if ($currency !== null && $currency !== $code) {
                continue;
            }
            $rows[$code] ??= $empty;
            $rows[$code][$adjustment->kind === 'refund' ? 'refunds' : 'corrections'] += $adjustment->principal;
        }
        foreach (\App\Models\LedgerAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNull('event_id')->where('category', \App\Enum\LedgerCategoryEnum::Giving)->where('code', \App\Models\LedgerAccount::CODE_ORGANIZER_PAYABLE)->when($currency, fn ($q) => $q->where('currency', $currency))->pluck('currency') as $code) {
            $rows[$code] ??= $empty;
        }
        foreach ($rows as $code => &$values) {
            $values['net_online'] = $values['collected_online'] - $values['refunds'];
            $values['available'] = app(GivingBalance::class)->available($tenant, $code);
        }
        unset($values);
        ksort($rows);

        return $rows;
    }
}
