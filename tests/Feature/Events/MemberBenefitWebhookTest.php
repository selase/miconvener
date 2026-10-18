<?php

declare(strict_types=1);

use App\Models\EventLedgerEntry;
use App\Models\TenantPaymentGateway;
use App\Services\Giving\GivingGateways;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\MemberBenefitScenario;

beforeEach(fn () => Mail::fake());

it('uses a signed original-account reference and holds late captures rather than falling through legacy fulfilment', function (string $provider): void {
    config(['services.settlement.paystack.secret_key' => 'sk_test_member_signed']);
    [$tenant, , $member, $registration, $quote, $attempt] = MemberBenefitScenario::make($provider);
    $key = 'sk_test_member_signed';
    $account = app(GivingGateways::class)->platformIdentity();
    $path = '/webhooks/settlement/paystack';
    if ($provider === 'own-paystack') {
        $row = TenantPaymentGateway::factory()->create(['tenant_id' => $tenant->id, 'provider' => 'paystack', 'api_key_encrypted' => $key, 'is_active' => true]);
        $account = app(GivingGateways::class)->identityForRow($row);
        $attempt->gateway_id = $row->id;
        $path = '/webhooks/merchant/paystack/'.$tenant->id;
    }
    $attempt->fill(['account_key' => $account])->save();
    $member->update(['status' => 'lapsed']);
    $this->travel(16)->minutes();
    $body = json_encode(['event' => 'charge.success', 'data' => ['id' => 71, 'reference' => $attempt->reference, 'amount' => 5000, 'currency' => 'GHS', 'fees' => 100,
        'paid_at' => $quote->expires_at->toIso8601String(), 'metadata' => ['source' => config('services.paystack.metadata_source'), 'type' => 'event_ticket', 'tenant_id' => $tenant->id, 'event_registration_id' => $registration->id]]]);
    $signature = hash_hmac('sha512', $body, $key);
    $this->withServerVariables(['HTTP_HOST' => 'miconvener.test'])->call('POST', $path, [], [], [], ['HTTP_x-paystack-signature' => $signature, 'CONTENT_TYPE' => 'application/json'], $body)->assertSuccessful();
    expect($attempt->fresh()->state)->toBe('paid_review')->and($registration->fresh()->ticket_code)->toBeNull()
        ->and(EventLedgerEntry::query()->where('registration_id', $registration->id)->count())->toBe(1);
})->with(['platform', 'own-paystack']);

it('does not fulfil a benefit-bound registration from an unbound provider reference', function (): void {
    config(['services.settlement.paystack.secret_key' => 'sk_test_member_signed']);
    [$tenant, , , $registration] = MemberBenefitScenario::make();
    $body = json_encode(['event' => 'charge.success', 'data' => ['id' => 71, 'reference' => 'unbound_provider_reference', 'amount' => 5000, 'currency' => 'GHS', 'fees' => 100,
        'metadata' => ['source' => config('services.paystack.metadata_source'), 'type' => 'event_ticket', 'tenant_id' => $tenant->id, 'event_registration_id' => $registration->id]]]);
    $signature = hash_hmac('sha512', $body, 'sk_test_member_signed');
    $this->withServerVariables(['HTTP_HOST' => 'miconvener.test'])->call('POST', '/webhooks/settlement/paystack', [], [], [], ['HTTP_x-paystack-signature' => $signature, 'CONTENT_TYPE' => 'application/json'], $body)->assertSuccessful();
    expect($registration->fresh()->ticket_code)->toBeNull()->and(EventLedgerEntry::query()->count())->toBe(0);
});

it('verifies a callback on its frozen account and does not grant private access merely from the callback URL', function (): void {
    config(['services.settlement.paystack.secret_key' => 'sk_test_member_callback']);
    [$tenant, $event, , $registration, , $attempt, $session] = MemberBenefitScenario::make();
    $attempt->update(['account_key' => app(GivingGateways::class)->platformIdentity()]);
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['data' => ['id' => 71, 'reference' => $attempt->reference, 'status' => 'success', 'amount' => 5000, 'currency' => 'GHS', 'fees' => 100, 'paid_at' => now()->toIso8601String()]])]);
    $session->flush();
    $host = eventSubdomainHost($tenant->slug);
    $this->withServerVariables(['HTTP_HOST' => $host])->get('http://'.$host.'/e/'.$event->slug.'/checkout/'.$registration->id.'/member-callback?attempt='.$attempt->id)->assertRedirect();
    expect($registration->fresh()->status)->toBe('confirmed');
    $this->get('http://'.$host.'/e/'.$event->slug.'/checkout/'.$registration->id)->assertForbidden();
});

it('uses signed Stripe completion evidence on the original connected account', function (): void {
    config(['services.stripe.connect.webhook_secret' => 'whsec_member_completion', 'services.stripe.secret' => 'sk_test_member_stripe']);
    [$tenant, , , $registration, $quote, $attempt] = MemberBenefitScenario::make('stripe');
    $gateway = TenantPaymentGateway::factory()->create(['tenant_id' => $tenant->id, 'provider' => 'stripe', 'provider_account_id' => 'acct_member', 'is_active' => true]);
    $attempt->update(['account_key' => app(GivingGateways::class)->identityForRow($gateway), 'gateway_id' => $gateway->id, 'provider_session_id' => 'cs_giving']);
    $fake = new Tests\Support\GivingStripeHttp;
    $fake->amount = 5000;
    $fake->metadata = ['type' => 'event_ticket', 'member_benefit_attempt_id' => $attempt->id];
    Stripe\ApiRequestor::setHttpClient($fake);
    $body = json_encode(['id' => 'evt_member', 'object' => 'event', 'type' => 'checkout.session.completed', 'created' => $quote->expires_at->subSecond()->timestamp,
        'account' => 'acct_member', 'data' => ['object' => ['id' => 'cs_giving', 'object' => 'checkout.session', 'payment_status' => 'paid', 'metadata' => ['type' => 'event_ticket']]]]);
    $timestamp = time();
    $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_member_completion');
    $this->withServerVariables(['HTTP_HOST' => 'miconvener.test'])->call('POST', '/webhooks/stripe/connect', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $body)->assertSuccessful();
    expect($registration->fresh()->status)->toBe('confirmed');
});
