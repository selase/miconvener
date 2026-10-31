<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Enum\LedgerCategoryEnum;
use App\Models\Event;
use App\Models\EventMemberBenefitAttempt;
use App\Models\EventPayout;
use App\Models\LedgerAccount;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;

final class MemberBenefitBalance
{
    public function applies(Event $event): bool
    {
        return $event->registrations()->where('member_benefit_required', true)->exists() || $this->standardHolds($event)->exists();
    }

    public function reservedRefunds(Event $event): int
    {
        $memberReserve = (int) $this->attempts($event)->where('settlement_mode', Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT)->whereNotNull('booked_at')
            ->whereIn('state', ['paid_review', 'paid_extra', 'refund_pending', 'refund_unknown'])->get(['charged_amount', 'platform_fee_amount'])
            ->sum(fn (EventMemberBenefitAttempt $attempt): int => max(0, $attempt->charged_amount - $attempt->platform_fee_amount));

        return $memberReserve + (int) $this->standardHolds($event)->get(['charged_amount', 'amount', 'platform_fee_amount'])->sum(fn (\App\Models\EventRegistration $registration): int => max(0, $registration->effectiveChargedAmount() - $registration->platform_fee_amount));
    }

    public function available(Event $event, ?string $exceptPayoutId = null): int
    {
        $account = LedgerAccount::withoutGlobalScopes()->where('tenant_id', $event->tenant_id)->where('event_id', $event->id)
            ->where('category', LedgerCategoryEnum::Event)->where('currency', $event->currency)->where('code', LedgerAccount::CODE_ORGANIZER_PAYABLE)->first();
        $committed = EventPayout::withoutGlobalScopes()->where('tenant_id', $event->tenant_id)->where('event_id', $event->id)
            ->where('currency', $event->currency)->whereIn('status', [EventPayout::STATUS_SCHEDULED, EventPayout::STATUS_PROCESSING, EventPayout::STATUS_AWAITING_OTP])
            ->when($exceptPayoutId !== null, fn ($query) => $query->where('id', '!=', $exceptPayoutId))->sum('amount');

        return max(0, (int) $account?->currentBalance() - (int) $committed - $this->reservedRefunds($event));
    }

    /** @return Builder<\App\Models\EventRegistration> */
    private function standardHolds(Event $event): Builder
    {
        $platformCredits = \App\Models\LedgerTransaction::withoutGlobalScopes()->select('reference')->where('tenant_id', $event->tenant_id)->where('event_id', $event->id)->where('currency', $event->currency)
            ->where('transaction_type', \App\Models\LedgerTransaction::TYPE_TICKET_SALE)
            ->whereHas('entries', fn ($query) => $query->where('direction', \App\Models\LedgerEntry::DIRECTION_CREDIT)->whereHas('account', fn ($account) => $account->where('code', LedgerAccount::CODE_ORGANIZER_PAYABLE)));

        return \App\Models\EventRegistration::withoutGlobalScopes()->where('tenant_id', $event->tenant_id)->where('event_id', $event->id)->where('currency', $event->currency)->where('member_benefit_required', false)
            ->where('status', \App\Models\EventRegistration::STATUS_PENDING_BENEFIT_REVIEW)->whereIn('payment_reference', $platformCredits);
    }

    /** @return Builder<EventMemberBenefitAttempt> */
    private function attempts(Event $event): Builder
    {
        return EventMemberBenefitAttempt::withoutGlobalScopes()->where('tenant_id', $event->tenant_id)->where('currency', $event->currency)
            ->whereHas('registration', fn ($query) => $query->withoutGlobalScopes()->where('tenant_id', $event->tenant_id)->where('event_id', $event->id));
    }
}
