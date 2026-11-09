<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enum\LedgerCategoryEnum;
use App\Models\Event;
use App\Models\LedgerAccount;
use App\Models\OrganisationPayoutPolicy;
use App\Models\Tenant;
use App\Services\Dues\DuesBalance;
use App\Services\Events\MemberBenefitBalance;
use App\Services\Giving\GivingBalance;
use App\Services\Marketplace\StoreBalance;
use Illuminate\Validation\ValidationException;

final class PayoutEligibility
{
    /** @return array{eligible:bool,reason:?string,entitlement:int,reserve:int,available:int,currency:string,fee:int,amount:int,net:int} */
    public function inspect(OrganisationPayoutPolicy $policy, ?string $eventId = null, ?string $excludePayoutId = null): array
    {
        app(PayoutPolicies::class)->assertActive($policy);
        app(PayoutReconciliationCheckpoints::class)->assertFresh($policy->account_fingerprint, $policy->tenant_id.':'.$policy->category);
        $tenant = Tenant::query()->findOrFail($policy->tenant_id);
        $category = LedgerCategoryEnum::from($policy->category);
        $event = $eventId === null ? null : Event::withoutGlobalScopes()->where('tenant_id', $tenant->id)->findOrFail($eventId);
        if (! $category->allowsEvent($event !== null) || ($event !== null && $event->currency !== 'GHS')) {
            throw ValidationException::withMessages(['schedule' => 'Choose the correct event and GHS accounting scope.']);
        }
        $entitlement = LedgerAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('event_id', $eventId)
            ->where('category', $category)->where('currency', 'GHS')->where('code', LedgerAccount::CODE_ORGANIZER_PAYABLE)->first()?->currentBalance() ?? 0;
        $available = match ($category) {
            LedgerCategoryEnum::Dues => app(DuesBalance::class)->available($tenant, 'GHS'),
            LedgerCategoryEnum::Giving => app(GivingBalance::class)->available($tenant, 'GHS'),
            LedgerCategoryEnum::Commerce => app(StoreBalance::class)->available($tenant, $excludePayoutId),
            LedgerCategoryEnum::Event => app(MemberBenefitBalance::class)->available($event, $excludePayoutId),
            default => 0,
        };
        if ($excludePayoutId !== null && in_array($category, [LedgerCategoryEnum::Dues, LedgerCategoryEnum::Giving], true)) {
            $own = \App\Models\EventPayout::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('category', $category)->where('currency', 'GHS')->find($excludePayoutId);
            if ($own !== null && in_array($own->status, DuesBalance::IN_FLIGHT_STATUSES, true)) {
                $available += $own->amount;
            }
        }
        $available = min(max(0, $entitlement), max(0, $available - $policy->reserve_amount));
        $account = app(PayoutPolicies::class)->assertDestination($policy);
        $fee = app(TransferFeeSchedule::class)->feeFor($account->type);
        $amount = min($available, $policy->maximum_amount);
        $eligible = $amount >= $policy->minimum_amount && $amount > $fee;

        return ['eligible' => $eligible, 'reason' => $eligible ? null : 'Available funds are below the approved minimum or transfer fee.',
            'entitlement' => $entitlement, 'reserve' => max(0, $entitlement - $available), 'available' => $available,
            'currency' => 'GHS', 'fee' => $fee, 'amount' => $amount, 'net' => max(0, $amount - $fee)];
    }
}
