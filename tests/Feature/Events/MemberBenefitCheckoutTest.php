<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventMemberBenefitAttempt;
use App\Models\EventRegistration;
use App\Models\Member;
use App\Models\Tenant;
use App\Services\Events\MemberBenefitCheckout;
use App\Services\Events\PlatformAttendeeVerification;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

function memberCheckoutFixture(bool $failInitialize = false): array
{
    config(['services.settlement.paystack.secret_key' => 'sk_test_member_checkout']);
    $tenant = Tenant::factory()->create(['slug' => 'member-checkout']);
    foreach (['community', 'community_event_benefits'] as $key) {
        $tenant->features()->create(['feature_key' => $key, 'enabled' => true]);
    }
    $event = Event::factory()->published()->paid(10000)->create(['tenant_id' => $tenant->id, 'member_ticket_price' => 5000, 'grandfathered_at' => now()]);
    $member = Member::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->pendingPayment()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'email' => $member->email, 'member_benefit_required' => true, 'amount' => 5000, 'charged_amount' => 5000, 'currency' => 'GHS']);
    $session = app('session.store');
    $session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]);
    Http::fake(['api.paystack.co/customer' => Http::response(['data' => ['customer_code' => 'CUS_member']]),
        'api.paystack.co/customer/*' => Http::response(['data' => ['email' => $member->email]]),
        'api.paystack.co/transaction/initialize' => $failInitialize ? Http::response(['message' => 'unavailable'], 503) : Http::response(['data' => ['authorization_url' => 'https://checkout.paystack.com/member']])]);

    return [$tenant, $event, $member, $registration, $session];
}

it('binds a checkout to our original reference and does not create another charge on retry', function (): void {
    [, , , $registration, $session] = memberCheckoutFixture();
    $checkout = app(MemberBenefitCheckout::class);
    expect($checkout->start($session, $registration, 'paystack', 5000, 'GHS'))->toBe('https://checkout.paystack.com/member');
    $attempt = EventMemberBenefitAttempt::query()->firstOrFail();
    expect($attempt->quote->registration_id)->toBe($registration->id)->and($attempt->state)->toBe('open');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'transaction/initialize') && $request['reference'] === $attempt->reference
        && $request['metadata']['member_benefit_attempt_id'] === $attempt->id && $request['amount'] === 5000);
    expect($checkout->start($session, $registration->fresh(), 'paystack', 5000, 'GHS'))->toBe('https://checkout.paystack.com/member');
    Http::assertSentCount(3);
});

it('requires accepted totals, current membership and a verified own email before initiating payment', function (string $change): void {
    [, , $member, $registration, $session] = memberCheckoutFixture();
    if ($change === 'lapsed') {
        $member->update(['status' => 'lapsed']);
    } elseif ($change === 'proof') {
        $session->forget(PlatformAttendeeVerification::SESSION_KEY);
    }
    expect(fn () => app(MemberBenefitCheckout::class)->start($session, $registration, 'paystack', $change === 'amount' ? 1 : 5000, $change === 'currency' ? 'USD' : 'GHS'))->toThrow(ValidationException::class);
    Http::assertNothingSent();
})->with(['amount', 'currency', 'lapsed', 'proof']);

it('blocks direct legacy provider routes from bypassing the member quote and recipient access', function (): void {
    [$tenant, $event, , $registration] = memberCheckoutFixture();
    $host = eventSubdomainHost($tenant->slug);
    $this->withServerVariables(['HTTP_HOST' => $host]);
    $this->postJson('http://'.$host.'/e/'.$event->slug.'/checkout/'.$registration->id.'/paystack')->assertUnprocessable();
    Http::assertNothingSent();
});

it('retains an unknown initialization outcome instead of creating a second payment', function (): void {
    [, , , $registration, $session] = memberCheckoutFixture(true);
    try {
        app(MemberBenefitCheckout::class)->start($session, $registration, 'paystack', 5000, 'GHS');
    } catch (ValidationException) {
    }
    expect(EventMemberBenefitAttempt::query()->firstOrFail()->state)->toBe('unknown');
    expect(fn () => app(MemberBenefitCheckout::class)->start($session, $registration->fresh(), 'paystack', 5000, 'GHS'))->toThrow(ValidationException::class);
    Http::assertSentCount(3);
});

it('renders benefit checkout without initiating a payment and requires workspace access for its action', function (): void {
    [$tenant, $event, , $registration, $session] = memberCheckoutFixture();
    $host = eventSubdomainHost($tenant->slug);
    $url = 'http://'.$host.'/e/'.$event->slug.'/checkout/'.$registration->id;
    $proof = $session->get(PlatformAttendeeVerification::SESSION_KEY);
    $this->withServerVariables(['HTTP_HOST' => $host])->withSession([PlatformAttendeeVerification::SESSION_KEY => null]);
    $this->postJson($url.'/member', ['provider' => 'paystack', 'expected_amount' => 5000, 'currency' => 'GHS'])->assertForbidden();
    $session->put(PlatformAttendeeVerification::SESSION_KEY, $proof);
    app(App\Services\Events\PlatformAttendeeWorkspaceAuthorizer::class)->rememberRegistered($session, $registration->id);
    $this->withSession($session->all())->get($url)->assertSuccessful();
    Http::assertNothingSent();
    $this->postJson($url.'/member', ['provider' => 'paystack', 'expected_amount' => 5000, 'currency' => 'GHS'])->assertRedirect('https://checkout.paystack.com/member');
});

it('does not resume a checkout while an additional captured payment needs refund or reconciliation', function (): void {
    [, , , $registration, $session] = memberCheckoutFixture();
    $service = app(MemberBenefitCheckout::class);
    $service->start($session, $registration, 'paystack', 5000, 'GHS');
    $current = EventMemberBenefitAttempt::query()->firstOrFail();
    EventMemberBenefitAttempt::factory()->forQuote($current->quote)->create(['state' => 'paid_extra', 'is_extra_capture' => true]);
    expect(fn () => $service->start($session, $registration->fresh(), 'paystack', 5000, 'GHS'))->toThrow(ValidationException::class);
    Http::assertSentCount(3);
});

it('refuses a known exhausted capacity before initiating a new member payment', function (): void {
    [$tenant, $event, , $registration, $session] = memberCheckoutFixture();
    $event->update(['capacity' => 1]);
    EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => 'confirmed']);
    expect(fn () => app(MemberBenefitCheckout::class)->start($session, $registration, 'paystack', 5000, 'GHS'))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});
