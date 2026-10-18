<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Event;
use App\Models\EventMemberBenefitAttempt;
use App\Models\EventRegistration;
use App\Models\Member;
use App\Models\Tenant;
use App\Services\Events\MemberBenefitQuotes;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Finance\FeeCalculator;
use Carbon\CarbonImmutable;

final class MemberBenefitScenario
{
    public static function make(string $provider = 'platform'): array
    {
        $tenant = Tenant::factory()->create(['settlement_mode' => $provider === 'platform' ? 'platform_default' : 'own_gateway']);
        foreach (['community', 'community_event_benefits'] as $key) {
            $tenant->features()->create(['feature_key' => $key, 'enabled' => true]);
        }
        $event = Event::factory()->published()->paid(10000)->create(['tenant_id' => $tenant->id, 'members_only' => true, 'member_ticket_price' => 5000, 'platform_fee_percentage' => 2, 'fee_bearer' => 'organizer', 'grandfathered_at' => now()]);
        $member = Member::factory()->create(['tenant_id' => $tenant->id]);
        $fees = app(FeeCalculator::class)->for($event, 5000);
        $registration = EventRegistration::factory()->pendingPayment()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'email' => $member->email, 'member_benefit_required' => true, 'amount' => 5000, 'charged_amount' => $fees->chargedAmount, 'platform_fee_amount' => $fees->platformFee, 'currency' => 'GHS', 'ticket_code' => null, 'qr_token' => null]);
        $session = app('session.store');
        $session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]);
        $quote = app(MemberBenefitQuotes::class)->start($session, $registration);
        $attempt = EventMemberBenefitAttempt::factory()->forQuote($quote)->create(['provider' => $provider === 'stripe' ? 'stripe' : 'paystack', 'account_key' => $provider.':synthetic', 'settlement_mode' => $tenant->settlement_mode, 'state' => 'open', 'commission_settlement' => ['commission_credit_applied' => 0, 'commission_collected' => $provider === 'stripe' ? $quote->platform_fee_amount : 0]]);

        return [$tenant, $event, $member, $registration->fresh(), $quote, $attempt, $session];
    }

    public static function payment(EventMemberBenefitAttempt $attempt): array
    {
        return ['status' => 'success', 'provider' => $attempt->provider, 'account_key' => $attempt->account_key, 'reference' => $attempt->reference,
            'provider_reference' => $attempt->provider === 'stripe' ? 'pi_'.str_replace('-', '', $attempt->id) : $attempt->reference,
            'amount' => $attempt->charged_amount, 'currency' => $attempt->currency, 'gateway_fee' => 100, 'paid_at' => CarbonImmutable::now(),
            'commission_received' => $attempt->provider === 'stripe' ? $attempt->platform_fee_amount : 0];
    }
}
