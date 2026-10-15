<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\CommunitySettings;
use App\Models\CommunitySmsReservation;
use App\Models\DuesReminder;
use App\Models\Tenant;
use App\Services\Sms\SmsAllowance;
use App\Services\Sms\SmsResult;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class CommunitySmsBudget
{
    public function __construct(private SmsAllowance $allowance) {}

    public function remaining(Tenant $tenant, CarbonImmutable $at): ?int
    {
        $limit = CommunitySettings::for($tenant)->sms_monthly_limit;

        return $limit === null ? null : max(0, $limit - $this->used($tenant, $at));
    }

    public function used(Tenant $tenant, CarbonImmutable $at): int
    {
        $local = $at->setTimezone(CommunitySettings::for($tenant)->timezone);
        $reserved = (int) CommunitySmsReservation::query()->where('tenant_id', $tenant->id)
            ->where('budget_month', $local->format('Y-m'))->whereIn('status', ['reserved', 'accepted'])->sum('credits');
        $legacy = DuesReminder::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('channel', 'sms')
            ->whereIn('status', ['reserved', 'sent'])->whereBetween('sent_for_date', [$local->startOfMonth()->toDateString(), $local->endOfMonth()->toDateString()])
            ->whereNotIn('id', CommunitySmsReservation::query()->where('tenant_id', $tenant->id)->where('source_type', 'dues_reminder')->select('source_id'))->count();

        $events = \App\Models\EventNotificationLog::withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->where('source_type', (new \App\Models\EventBlast)->getMorphClass())->where('channel', 'sms')->whereIn('status', ['sent', 'staged_omnichannel'])
            ->whereBetween('sent_at', [$local->startOfMonth()->utc(), $local->endOfMonth()->utc()])
            ->whereNotIn('id', CommunitySmsReservation::query()->where('tenant_id', $tenant->id)->where('source_type', 'event_blast')->select('source_id'))->count();

        return $reserved + $legacy + $events;
    }

    public function reserve(Tenant $tenant, string $sourceType, string $sourceId, CarbonImmutable $at): ?CommunitySmsReservation
    {
        if (! in_array($sourceType, ['dues_reminder', 'community_message', 'event_blast'], true)) {
            throw new InvalidArgumentException('Unknown Community SMS source.');
        }

        return $this->allowance->withLock($tenant, function () use ($tenant, $sourceType, $sourceId, $at): ?CommunitySmsReservation {
            $existing = CommunitySmsReservation::query()->where('tenant_id', $tenant->id)->where('source_type', $sourceType)->where('source_id', $sourceId)->first();
            if ($existing !== null) {
                return $existing;
            }
            $remaining = $this->remaining($tenant, $at);
            if ($remaining !== null && $remaining < 1) {
                return null;
            }
            $receipt = $this->allowance->reserve($tenant, 1);
            if ($receipt === null) {
                return null;
            }

            return CommunitySmsReservation::query()->create([
                'tenant_id' => $tenant->id, 'source_type' => $sourceType, 'source_id' => $sourceId,
                'credit_receipt_id' => $receipt->id, 'credits' => 1, 'status' => 'reserved',
                'reserved_at' => $at, 'budget_month' => $at->setTimezone(CommunitySettings::for($tenant)->timezone)->format('Y-m'),
            ]);
        });
    }

    public function finish(Tenant $tenant, string $reservationId, SmsResult $result): void
    {
        $this->allowance->withLock($tenant, function () use ($tenant, $reservationId, $result): void {
            $row = CommunitySmsReservation::query()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($reservationId);
            if ($row->status !== 'reserved') {
                return;
            }
            if ($result->accepted) {
                $row->update(['status' => 'accepted', 'provider_reference' => $result->providerReference, 'reason' => null]);
            } elseif ($result->uncertain) {
                $row->update(['reason' => 'outcome_unknown']);
            } else {
                $this->allowance->refund($tenant, $row->credit_receipt_id);
                $row->update(['status' => 'refused', 'reason' => $result->isThrottled() ? 'provider_throttled' : 'provider_refused']);
            }
        });
    }
}
