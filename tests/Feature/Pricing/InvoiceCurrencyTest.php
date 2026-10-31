<?php

declare(strict_types=1);

use App\Contracts\PaymentGateway;
use App\Enum\UsageMetric;
use App\Mail\Billing\PaymentReceiptMail;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\UsagePrice;
use App\Models\User;
use App\Services\Billing\BillingNotifier;
use Illuminate\Support\Facades\Mail;

/**
 * Paystack charges the platform in GHS, so invoices and receipts say GHS, and a
 * payment priced in USD shows the USD price and the rate it was converted at.
 */
test('an invoice still labelled USD is paid in GHS, the only currency Paystack takes', function () {
    config(['services.payment.default' => 'paystack', 'services.paystack.currency' => 'GHS']);
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user, ['meta' => ['paystack_id' => 'CUS_123']]);
    makeTenantOwner($user, $tenant);
    $invoice = Invoice::create([
        'tenant_id' => $tenant->id, 'number' => 'INV-USD', 'total' => 100, 'currency' => 'USD',
        'status' => Invoice::STATUS_ISSUED, 'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(),
    ]);
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('createOneTimeCheckoutSession')->once()->with('CUS_123', 10000, 'GHS', Mockery::any(), Mockery::any())->andReturn('https://paystack.com/i');
    $this->swap(PaymentGateway::class, $gateway);

    $this->actingAs($user)->post(route('billing.checkout', ['subdomain' => $tenant->slug]), ['invoice_id' => $invoice->id])->assertRedirect('https://paystack.com/i');
});

test('the global rate card is priced in GHS', function () {
    $superadmin = User::factory()->create(['tenant_id' => null]);
    $superadmin->assignRole(Role::create(['name' => 'Superadmin', 'guard_name' => 'web']));

    $this->actingAs($superadmin)
        ->post(route('admin.billing.rate-cards.prices.update'), ['usage_prices' => [UsageMetric::REQUEST_COUNT->value => ['unit_price' => '0.01', 'unit_quantity' => '1']]])
        ->assertSessionHas('success');

    expect(UsagePrice::query()->whereNull('target_type')->value('currency'))->toBe('GHS');
});

test('the receipt for a USD price shows the GHS charged and the USD price with its rate', function () {
    Mail::fake();
    $user = User::factory()->create(['email' => 'payer@acme.test']);
    $tenant = setActiveTenantForTest($user);
    Transaction::create([
        'tenant_id' => $tenant->id, 'amount' => 22363, 'currency' => 'ghs', 'status' => 'success', 'type' => 'charge',
        'provider' => 'paystack', 'provider_transaction_id' => 'ref_usd_receipt',
        'meta' => ['type' => 'llm_token_purchase', 'pricing' => ['display_currency' => 'USD', 'display_amount_minor' => 1900, 'fx_rate' => 11.77, 'fx_as_of' => '2026-10-16', 'fx_source' => 'bank_of_ghana']],
    ]);

    app(BillingNotifier::class)->paymentReceived($tenant, 'ref_usd_receipt', 'payer@acme.test', ['amount' => 22363, 'currency' => 'GHS']);

    Mail::assertQueued(PaymentReceiptMail::class, fn (PaymentReceiptMail $mail): bool => $mail->amountDisplay === 'GHS 223.63'
        && $mail->priceReference === 'US$ 19 at the Bank of Ghana rate of 11.77 (16 Oct 2026)');
});
