<?php

declare(strict_types=1);

use App\Models\EventLedgerEntry;
use App\Services\Events\MemberBenefitPayments;
use Illuminate\Support\Facades\Mail;
use Tests\Support\MemberBenefitScenario;

beforeEach(fn () => Mail::fake());

it('lets only a freshly eligible recipient recover a paid review without another charge', function (): void {
    [, , $member, $registration, $quote, $attempt, $session] = MemberBenefitScenario::make();
    $payment = MemberBenefitScenario::payment($attempt);
    $payment['paid_at'] = $quote->expires_at;
    $service = app(MemberBenefitPayments::class);
    $service->confirm($attempt, $payment);
    $member->update(['status' => 'lapsed']);
    expect($service->reassess($registration->fresh(), $session))->toBeFalse();
    $member->update(['status' => 'active']);
    expect($service->reassess($registration->fresh(), $session))->toBeTrue()
        ->and($service->reassess($registration->fresh(), $session))->toBeTrue()
        ->and($registration->fresh()->status)->toBe('confirmed')
        ->and(EventLedgerEntry::query()->where('registration_id', $registration->id)->count())->toBe(1);
});

it('does not fulfil when a fee is missing and can recover once exact provider evidence arrives', function (): void {
    [, , , $registration, , $attempt] = MemberBenefitScenario::make();
    $payment = MemberBenefitScenario::payment($attempt);
    $payment['gateway_fee'] = null;
    $service = app(MemberBenefitPayments::class);
    $service->confirm($attempt, $payment);
    expect($attempt->fresh()->state)->toBe('paid_review')->and($attempt->fresh()->review_reason)->toBe('gateway_fee_pending')
        ->and($registration->fresh()->ticket_code)->toBeNull()->and(EventLedgerEntry::query()->count())->toBe(0);
    $payment['gateway_fee'] = 100;
    $service->confirm($attempt->fresh(), $payment);
    expect($registration->fresh()->status)->toBe('confirmed')->and(EventLedgerEntry::query()->count())->toBe(1);
});

it('requests a full refund once then confirms its original transaction before posting a reversal', function (): void {
    config(['services.settlement.paystack.secret_key' => 'sk_test_member_refund']);
    [, , , $registration, $quote, $attempt] = MemberBenefitScenario::make();
    $attempt->update(['account_key' => app(App\Services\Giving\GivingGateways::class)->platformIdentity()]);
    $payment = MemberBenefitScenario::payment($attempt);
    $payment['paid_at'] = $quote->expires_at;
    app(MemberBenefitPayments::class)->confirm($attempt, $payment);
    Illuminate\Support\Facades\Http::fake([
        'api.paystack.co/refund' => Illuminate\Support\Facades\Http::response(['data' => ['id' => 42, 'status' => 'pending']]),
        'api.paystack.co/refund/42' => fn () => Illuminate\Support\Facades\Http::response(['data' => ['id' => 42, 'status' => 'processed', 'transaction' => 71,
            'amount' => 5000, 'currency' => 'GHS', 'merchant_note' => 'giving:'.$attempt->fresh()->refund_operation_key]]),
        'api.paystack.co/transaction/verify/*' => Illuminate\Support\Facades\Http::response(['data' => ['id' => 71, 'reference' => $attempt->reference, 'status' => 'reversed', 'amount' => 5000, 'currency' => 'GHS']]),
    ]);
    $refunds = app(App\Services\Events\MemberBenefitRefunds::class);
    $refunds->request($attempt->fresh());
    $refunds->request($attempt->fresh());
    expect($attempt->fresh()->state)->toBe('refund_pending')->and($registration->fresh()->ticket_code)->toBeNull();
    Illuminate\Support\Facades\Http::assertSentCount(1);
    $refunds->reconcile($attempt->fresh());
    $refunds->reconcile($attempt->fresh());
    expect($attempt->fresh()->state)->toBe('refunded')->and($registration->fresh()->status)->toBe('cancelled')
        ->and(EventLedgerEntry::query()->where('registration_id', $registration->id)->count())->toBe(2)
        ->and((int) EventLedgerEntry::query()->where('registration_id', $registration->id)->sum('net_amount'))->toBe(-100);
    Illuminate\Support\Facades\Http::assertSentCount(3);
});

it('holds an uncertain refund and never resends it when the operator retries', function (): void {
    config(['services.settlement.paystack.secret_key' => 'sk_test_member_refund']);
    [, , , , $quote, $attempt] = MemberBenefitScenario::make();
    $attempt->update(['account_key' => app(App\Services\Giving\GivingGateways::class)->platformIdentity()]);
    $payment = MemberBenefitScenario::payment($attempt);
    $payment['paid_at'] = $quote->expires_at;
    app(MemberBenefitPayments::class)->confirm($attempt, $payment);
    Illuminate\Support\Facades\Http::fake(['api.paystack.co/refund' => Illuminate\Support\Facades\Http::response(['message' => 'timeout'], 503)]);
    $refunds = app(App\Services\Events\MemberBenefitRefunds::class);
    $refunds->request($attempt->fresh());
    $refunds->request($attempt->fresh());
    expect($attempt->fresh()->state)->toBe('refund_unknown')->and($attempt->fresh()->refund_operation_key)->not->toBeNull();
    Illuminate\Support\Facades\Http::assertSentCount(1);
});

it('keeps a current valid ticket when an additional old capture is refunded', function (): void {
    config(['services.settlement.paystack.secret_key' => 'sk_test_extra_refund']);
    [, , , $registration, $quote, $old] = MemberBenefitScenario::make();
    $old->update(['account_key' => app(App\Services\Giving\GivingGateways::class)->platformIdentity(), 'state' => 'failed']);
    $current = App\Models\EventMemberBenefitAttempt::factory()->forQuote($quote)->create(['state' => 'open', 'account_key' => $old->account_key]);
    $payments = app(MemberBenefitPayments::class);
    $payments->confirm($old->fresh(), MemberBenefitScenario::payment($old));
    $payments->confirm($current, MemberBenefitScenario::payment($current));
    $ticket = $registration->fresh()->ticket_code;
    Illuminate\Support\Facades\Http::fake([
        'api.paystack.co/refund' => Illuminate\Support\Facades\Http::response(['data' => ['id' => 42, 'status' => 'pending']]),
        'api.paystack.co/refund/42' => fn () => Illuminate\Support\Facades\Http::response(['data' => ['id' => 42, 'status' => 'processed', 'transaction' => 71,
            'amount' => 5000, 'currency' => 'GHS', 'merchant_note' => 'giving:'.$old->fresh()->refund_operation_key]]),
        'api.paystack.co/transaction/verify/*' => Illuminate\Support\Facades\Http::response(['data' => ['id' => 71, 'reference' => $old->reference, 'status' => 'reversed', 'amount' => 5000, 'currency' => 'GHS']]),
    ]);
    $refunds = app(App\Services\Events\MemberBenefitRefunds::class);
    $refunds->request($old->fresh());
    $refunds->reconcile($old->fresh());
    expect($old->fresh()->state)->toBe('refunded')->and($registration->fresh()->status)->toBe('confirmed')->and($registration->fresh()->ticket_code)->toBe($ticket);
});

it('requires workspace access and fresh personal proof for paid reassessment', function (): void {
    [$tenant, $event, , $registration, $quote, $attempt, $session] = MemberBenefitScenario::make();
    $payment = MemberBenefitScenario::payment($attempt);
    $payment['paid_at'] = $quote->expires_at;
    app(MemberBenefitPayments::class)->confirm($attempt, $payment);
    $host = eventSubdomainHost($tenant->slug);
    $url = 'http://'.$host.'/e/'.$event->slug.'/checkout/'.$registration->id.'/member-benefits/reassess';
    $proof = $session->all();
    $session->flush();
    $this->withServerVariables(['HTTP_HOST' => $host])->postJson($url)->assertForbidden();
    $this->withSession($proof)->postJson($url)->assertSuccessful();
    expect($registration->fresh()->status)->toBe('confirmed');
});

it('bounds operator refund and reconciliation to event permission and the original tenant', function (): void {
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    config(['services.settlement.paystack.secret_key' => 'sk_test_staff_refund']);
    [$tenant, $event, , , $quote, $attempt] = MemberBenefitScenario::make();
    $attempt->update(['account_key' => app(App\Services\Giving\GivingGateways::class)->platformIdentity()]);
    $payment = MemberBenefitScenario::payment($attempt);
    $payment['paid_at'] = $quote->expires_at;
    app(MemberBenefitPayments::class)->confirm($attempt, $payment);
    $user = App\Models\User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($user->id);
    setPermissionsTeamId($tenant->id);
    $host = eventSubdomainHost($tenant->slug);
    $url = 'http://'.$host.'/events/'.$event->id.'/member-payments/'.$attempt->id;
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => $host])->postJson($url.'/refund')->assertForbidden();
    $user->givePermissionTo('update event');
    Illuminate\Support\Facades\Http::fake(['api.paystack.co/refund' => Illuminate\Support\Facades\Http::response(['data' => ['id' => 42, 'status' => 'pending']]),
        'api.paystack.co/refund/42' => Illuminate\Support\Facades\Http::response(['data' => ['status' => 'pending']])]);
    $this->postJson($url.'/refund')->assertSuccessful();
    $this->postJson($url.'/reconcile', ['provider_refund_id' => str_repeat('x', 256)])->assertUnprocessable();
    $this->postJson($url.'/reconcile')->assertSuccessful();
    $other = App\Models\Event::factory()->create();
    $this->postJson('http://'.$host.'/events/'.$other->id.'/member-payments/'.$attempt->id.'/refund')->assertNotFound();
    expect($attempt->fresh()->state)->toBe('refund_pending');
});

it('reconciles connected Stripe refunds with actual returned commission and finance records', function (): void {
    config(['services.stripe.secret' => 'sk_test_member_refund_stripe']);
    [$tenant, , , $registration, , $attempt] = MemberBenefitScenario::make('stripe');
    $gateway = App\Models\TenantPaymentGateway::factory()->create(['tenant_id' => $tenant->id, 'provider' => 'stripe', 'provider_account_id' => 'acct_refund_member', 'is_active' => true]);
    $attempt->update(['account_key' => app(App\Services\Giving\GivingGateways::class)->identityForRow($gateway), 'gateway_id' => $gateway->id]);
    $payment = MemberBenefitScenario::payment($attempt);
    $payment['provider_reference'] = 'pi_giving';
    app(MemberBenefitPayments::class)->confirm($attempt, $payment);
    $fake = new Tests\Support\GivingStripeHttp;
    $fake->amount = 5000;
    Stripe\ApiRequestor::setHttpClient($fake);
    $refunds = app(App\Services\Events\MemberBenefitRefunds::class);
    $refunds->request($attempt->fresh());
    $refunds->reconcile($attempt->fresh());
    expect($attempt->fresh()->state)->toBe('refund_pending')->and($attempt->fresh()->review_reason)->toBe('refund_commission_pending');
    $fake->feeRefunded = $attempt->platform_fee_amount;
    $pendingFeeEvidence = app(App\Services\Events\MemberBenefitRefunds::class)->reconcile($attempt->fresh());
    expect($pendingFeeEvidence->state)->toBe('refund_pending')->and($pendingFeeEvidence->review_reason)->toBe('refund_fee_pending');
    foreach ($fake->refunds as &$refund) {
        $refund['balance_transaction'] = ['object' => 'balance_transaction', 'id' => 'txn_refund_member', 'currency' => 'ghs', 'fee' => 0];
    }
    unset($refund);

    $refunds->reconcile($attempt->fresh());
    $refunds->reconcile($attempt->fresh());
    expect($attempt->fresh()->state)->toBe('refunded')->and($registration->fresh()->status)->toBe('cancelled')
        ->and(App\Models\MerchantTransaction::withoutGlobalScopes()->where('provider_transaction_id', 'pi_giving')->value('status'))->toBe('refunded')
        ->and(App\Models\MerchantTransaction::withoutGlobalScopes()->where('type', 'refund')->count())->toBe(1);
    foreach ($fake->requests as $request) {
        if (! str_contains($request['url'], 'application_fees')) {
            expect($request['headers'])->toContain('Stripe-Account: acct_refund_member');
        }
    }
    expect(collect($fake->requests)->where('method', 'post')->count())->toBe(1);
});

it('routes an existing finance refund through the original member payment without resending', function (): void {
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    [$tenant, , , , , $attempt] = MemberBenefitScenario::make('own-paystack');
    $gateway = App\Models\TenantPaymentGateway::factory()->create(['tenant_id' => $tenant->id, 'provider' => 'paystack', 'api_key_encrypted' => 'sk_test_finance_member', 'is_active' => true]);
    $attempt->update(['account_key' => app(App\Services\Giving\GivingGateways::class)->identityForRow($gateway), 'gateway_id' => $gateway->id]);
    app(MemberBenefitPayments::class)->confirm($attempt, MemberBenefitScenario::payment($attempt));
    $transaction = App\Models\MerchantTransaction::withoutGlobalScopes()->where('provider_transaction_id', $attempt->fresh()->provider_reference)->sole();
    $user = App\Models\User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($user->id);
    setPermissionsTeamId($tenant->id);
    $user->givePermissionTo('process refunds');
    Illuminate\Support\Facades\Http::fake(['api.paystack.co/refund' => Illuminate\Support\Facades\Http::response(['data' => ['id' => 42, 'status' => 'pending']])]);
    $host = eventSubdomainHost($tenant->slug);
    $url = 'http://'.$host.'/finance/refund/'.$transaction->id;
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => $host])->post($url)->assertRedirect();
    $this->post($url)->assertRedirect();
    expect($attempt->fresh()->state)->toBe('refund_pending')->and($transaction->fresh()->status)->toBe('succeeded');
    Illuminate\Support\Facades\Http::assertSentCount(1);
});

it('refunds an issued archived event ticket without promoting archived waitlists', function (): void {
    config(['services.settlement.paystack.secret_key' => 'sk_test_archived_refund']);
    [, $event, , $registration, , $attempt] = MemberBenefitScenario::make();
    $attempt->update(['account_key' => app(App\Services\Giving\GivingGateways::class)->platformIdentity()]);
    app(MemberBenefitPayments::class)->confirm($attempt, MemberBenefitScenario::payment($attempt));
    $event->delete();
    Illuminate\Support\Facades\Http::fake(['api.paystack.co/refund' => Illuminate\Support\Facades\Http::response(['data' => ['id' => 42, 'status' => 'pending']])]);
    app(App\Services\Events\MemberBenefitRefunds::class)->request($attempt->fresh());
    expect($attempt->fresh()->state)->toBe('refund_pending')->and($registration->fresh()->status)->toBe('cancelled');
    Illuminate\Support\Facades\Http::assertSentCount(1);
});
