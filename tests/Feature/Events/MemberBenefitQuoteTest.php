<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventMemberBenefitAttempt;
use App\Models\EventMemberBenefitQuote;
use App\Models\EventRegistration;
use App\Models\Member;
use App\Models\Tenant;
use App\Services\Events\MemberBenefitQuotes;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Events\RegistrationPricingService;
use Illuminate\Validation\ValidationException;

function memberQuoteFixture(): array
{
    $tenant = Tenant::factory()->create();
    foreach (['community', 'community_event_benefits'] as $key) {
        $tenant->features()->create(['feature_key' => $key, 'enabled' => true]);
    }
    $event = Event::factory()->published()->paid(10000)->create(['tenant_id' => $tenant->id, 'members_only' => true, 'member_ticket_price' => 5000]);
    $member = Member::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->pendingPayment()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'email' => $member->email, 'amount' => 5000, 'currency' => 'GHS', 'member_benefit_required' => true]);
    $session = app('session.store');
    $session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]);

    return [$tenant, $event, $member, $registration, $session];
}

it('uses a nullable member base without changing existing three argument callers', function (?int $base, int $expected): void {
    $event = Event::factory()->paid(10000)->create();
    expect(app(RegistrationPricingService::class)->calculatePrice($event, null, [], $base)['amount'])->toBe($expected)
        ->and(app(RegistrationPricingService::class)->calculatePrice($event, null, [])['amount'])->toBe(10000);
})->with([[null, 10000], [0, 0], [5000, 5000]]);

it('freezes accepted totals and never slides quote expiry on retry or event price changes', function (): void {
    [, $event, , $registration, $session] = memberQuoteFixture();
    $this->freezeSecond();
    $quotes = app(MemberBenefitQuotes::class);
    $first = $quotes->start($session, $registration);
    expect($first->amount)->toBe(5000)->and($first->expires_at->timestamp)->toBe(now()->addMinutes(15)->timestamp);
    $event->update(['member_ticket_price' => 6000, 'platform_fee_percentage' => 25]);
    $this->travel(10)->minutes();
    $retry = $quotes->start($session, $registration->fresh());
    expect($retry->id)->toBe($first->id)->and($retry->charged_amount)->toBe($first->charged_amount)
        ->and($retry->expires_at->timestamp)->toBe($first->expires_at->timestamp)
        ->and(EventMemberBenefitQuote::query()->count())->toBe(1);
});

it('requires a fresh accepted amount rather than silently repricing', function (): void {
    [, $event, , $registration, $session] = memberQuoteFixture();
    $event->update(['member_ticket_price' => 7000]);
    expect(fn () => app(MemberBenefitQuotes::class)->start($session, $registration))->toThrow(ValidationException::class)
        ->and(EventMemberBenefitQuote::query()->count())->toBe(0);
});

it('rejects stale identity and mismatched carts independently of quote expiry', function (string $change): void {
    [, , $member, $registration, $session] = memberQuoteFixture();
    $quotes = app(MemberBenefitQuotes::class);
    $quote = $quotes->start($session, $registration);
    if ($change === 'identity') {
        $member->update(['email' => 'changed@example.test']);
    } else {
        $registration->update(['form_answers' => ['meal' => 'new']]);
    }
    expect(fn () => $quotes->assertUsable($quote, $registration->fresh()))->toThrow(ValidationException::class);
})->with(['identity', 'cart']);

it('treats the exact fifteen minute boundary as expired', function (): void {
    [, , , $registration, $session] = memberQuoteFixture();
    $this->freezeSecond();
    $quotes = app(MemberBenefitQuotes::class);
    $quote = $quotes->start($session, $registration);
    $this->travel(899)->seconds();
    $quotes->assertUsable($quote, $registration->fresh());
    $this->travel(1)->seconds();
    expect(fn () => $quotes->assertUsable($quote, $registration->fresh()))->toThrow(ValidationException::class);
});

it('does not give approval or waitlist registrations a payable quote', function (string $state): void {
    [, , , $registration, $session] = memberQuoteFixture();
    $registration->update(['status' => $state]);
    expect(fn () => app(MemberBenefitQuotes::class)->start($session, $registration))->toThrow(ValidationException::class);
})->with(['pending_approval', 'waitlisted']);

it('rejects a second unresolved attempt under the same quote and hides identity evidence', function (): void {
    [, , , $registration, $session] = memberQuoteFixture();
    $quote = app(MemberBenefitQuotes::class)->start($session, $registration);
    $attempt = EventMemberBenefitAttempt::factory()->forQuote($quote)->create();
    expect($attempt->quote->id)->toBe($quote->id)->and($quote->toArray())->not->toHaveKeys(['identity', 'cart_fingerprint']);
    expect(fn () => EventMemberBenefitAttempt::factory()->forQuote($quote)->create())->toThrow(Illuminate\Database\QueryException::class);
});

it('applies existing form overrides then additions and an already reserved promo before fees', function (): void {
    [$tenant, $event, , $registration] = memberQuoteFixture();
    foreach ([['format', 8000, true], ['meal', 1000, false]] as [$key, $amount, $override]) {
        $event->formFields()->create(['tenant_id' => $tenant->id, 'field_key' => $key, 'label' => $key, 'field_type' => 'select',
            'options' => [['value' => 'yes', 'label' => 'Yes', 'price' => $amount, 'is_override' => $override]], 'sort_order' => $key === 'format' ? 0 : 1]);
    }
    $promo = App\Models\EventPromoCode::query()->create(['code' => 'MEMBER10', 'is_active' => true, 'tenant_id' => $tenant->id, 'event_id' => $event->id, 'discount_type' => 'percentage', 'discount_value' => 10]);
    $registration->update(['form_answers' => ['format' => 'yes', 'meal' => 'yes'], 'promo_code_id' => $promo->id]);
    $price = app(MemberBenefitQuotes::class)->pricing($registration->fresh());
    expect($price['amount'])->toBe(8100)->and($price['discount_amount'])->toBe(900);
});

it('freezes the correct fee bearer and respects an explicit commission waiver', function (string $bearer, float $percentage, int $charged): void {
    [, $event, , $registration, $session] = memberQuoteFixture();
    $event->update(['fee_bearer' => $bearer, 'platform_fee_percentage' => $percentage, 'platform_fee_cap_amount' => null]);
    $registration->update(['charged_amount' => $charged]);
    $quote = app(MemberBenefitQuotes::class)->start($session, $registration->fresh());
    expect($quote->charged_amount)->toBe($charged)->and($quote->platform_fee_amount)->toBe($percentage === 0.0 ? 0 : 500)
        ->and($quote->pricing['fees']['bearer'])->toBe($bearer);
})->with([['organizer', 10.0, 5000], ['attendee', 10.0, 5500], ['attendee', 0.0, 5000]]);

it('normalises the accepted fee breakdown before freezing a fulfilment fingerprint', function (): void {
    [, , , $registration, $session] = memberQuoteFixture();
    $registration->update(['platform_fee_amount' => 0]);
    $quote = app(MemberBenefitQuotes::class)->start($session, $registration->fresh());
    $attempt = EventMemberBenefitAttempt::factory()->forQuote($quote)->create(['provider' => 'paystack', 'account_key' => 'platform:synthetic', 'settlement_mode' => 'platform_default', 'state' => 'open']);
    app(App\Services\Events\MemberBenefitPayments::class)->confirm($attempt, Tests\Support\MemberBenefitScenario::payment($attempt));
    expect($attempt->fresh()->state)->toBe('fulfilled')->and($registration->fresh()->platform_fee_amount)->toBe($quote->platform_fee_amount);
});
