<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\CommunityInboxItem;
use App\Models\DuesCharge;
use App\Models\Member;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

final class InboxNotices
{
    public function __construct(private InboxRollout $rollout) {}

    /** @param Collection<int,DuesCharge> $charges */
    public function reminder(Tenant $tenant, Member $member, CarbonImmutable $localDay, Collection $charges): void
    {
        $source = $tenant->id.':'.$member->id.':'.$localDay->toDateString();
        if (! CommunityInboxItem::query()->where('tenant_id', $tenant->id)->where('member_id', $member->id)->where('kind', 'dues_reminder')->where('source_id', $source)->exists() && \App\Models\DuesReminder::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('member_id', $member->id)->whereDate('sent_for_date', $localDay->toDateString())->exists()) {
            return;
        }
        $this->write($tenant, $member, 'dues_reminder', 'dues_reminder_day', $tenant->id.':'.$member->id.':'.$localDay->toDateString(), 'Your dues reminder', $charges, CarbonImmutable::now());
    }

    /** @param Collection<int,DuesCharge> $paidCharges */
    public function receipt(Tenant $tenant, Member $member, string $paymentReference, Collection $paidCharges, CarbonImmutable $confirmedAt): void
    {
        $offline = $paidCharges->every(fn (DuesCharge $charge): bool => $charge->payment_method === 'offline');
        $this->write($tenant, $member, 'dues_receipt', $offline ? 'dues_offline_charge' : 'dues_payment', $paymentReference, 'Your dues payment receipt', $paidCharges, $confirmedAt);
    }

    /** @param Collection<int,DuesCharge> $charges */
    private function write(Tenant $tenant, Member $member, string $kind, string $sourceType, string $sourceId, string $subject, Collection $charges, CarbonImmutable $availableAt): void
    {
        if ($charges->isEmpty() || $member->tenant_id !== $tenant->id || ! $this->rollout->enabled($tenant) || $charges->contains(fn (DuesCharge $charge): bool => $charge->tenant_id !== $tenant->id || $charge->member_id !== $member->id)) {
            return;
        }
        $lines = $charges->map(fn (DuesCharge $charge): string => $charge->period_start->toDateString().' – '.$charge->period_end->toDateString().': '.$charge->currency.' '.number_format((int) $charge->amount / 100, 2, '.', '').' · due '.$charge->due_on->toDateString())->all();
        $totals = $charges->groupBy('currency')->map(fn ($rows, string $currency): string => $currency.' '.number_format((int) $rows->sum('amount') / 100, 2, '.', ''))->implode('; ');
        $body = implode("\n", $lines)."\nTotal: ".$totals."\nOldest due date: ".$charges->min('due_on')->toDateString()."\nReview your records in Money.";
        CommunityInboxItem::query()->firstOrCreate(['tenant_id' => $tenant->id, 'member_id' => $member->id, 'kind' => $kind, 'source_type' => $sourceType, 'source_id' => $sourceId], ['recipient_type' => 'member', 'subject' => $subject, 'body' => $body, 'available_at' => $availableAt]);
    }
}
