<?php

declare(strict_types=1);

use App\Contracts\PaymentGateway;
use App\Mail\Billing\PaymentReceiptMail;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payment\PaystackGateway;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Tenants hear from MiConvener, by email, about every payment they make to it
 * and every change to their plan that follows from one — exactly once per
 * event, however many times the browser or Paystack reports that event.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);
    config([
        'services.paystack.secret_key' => 'sk_one_account',
        'services.settlement.paystack.secret_key' => 'sk_one_account',
        'services.paystack.metadata_source' => 'miconvener',
    ]);
    Mail::fake();
});

/**
 * @return array{0: Tenant, 1: User}
 */
function billingEmailTenant(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'name' => 'Acme Events', 'isolation_mode' => 'shared', 'email' => 'Accounts@Acme.test', 'llm_topup_balance' => 0]);
    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'owner@example.com']);
    $tenant->users()->attach($owner->id);
    setPermissionsTeamId($tenant->id);
    $owner->assignRole('Org Superadmin');

    return [$tenant, $owner];
}

/**
 * @param  array<string, mixed>  $data
 */
function billingWebhook(string $event, array $data): void
{
    $data['metadata'] = ($data['metadata'] ?? []) + ['source' => 'miconvener'];
    $body = json_encode(['event' => $event, 'data' => $data]);

    test()->call('POST', '/webhooks/paystack', [], [], [], [
        'HTTP_x-paystack-signature' => hash_hmac('sha512', $body, 'sk_one_account'),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();
}

test('a token pack payment sends one receipt to the payer and the tenant contact', function (): void {
    [$tenant] = billingEmailTenant();
    $charge = [
        'reference' => 'ref_receipt_tokens',
        'amount' => 500,
        'currency' => 'USD',
        'channel' => 'card',
        'paid_at' => '2026-09-15T10:00:00Z',
        'authorization' => ['last4' => '4081', 'brand' => 'visa'],
        'customer' => ['email' => 'owner@example.com'],
        'metadata' => ['type' => 'llm_token_purchase', 'pack_key' => 'starter', 'tenant_id' => $tenant->id],
    ];

    billingWebhook('charge.success', $charge);
    billingWebhook('charge.success', $charge);

    Mail::assertQueuedCount(1);
    Mail::assertQueued(PaymentReceiptMail::class, fn (PaymentReceiptMail $mail): bool => $mail->hasTo('owner@example.com')
        && $mail->hasTo('accounts@acme.test')
        && $mail->amountDisplay === 'USD 5.00'
        && $mail->description === 'Starter Pack: 500,000 AI tokens'
        && $mail->paymentMethod === 'Visa card ending 4081'
        && $mail->paidOn === '15 September 2026'
        && $mail->activePlan === null);
});

test('a subscription payment receipt names the plan that is now active', function (): void {
    [$tenant] = billingEmailTenant();

    billingWebhook('charge.success', [
        'reference' => 'ref_receipt_plan',
        'amount' => 9900,
        'currency' => 'GHS',
        'channel' => 'mobile_money',
        'customer' => ['email' => 'owner@example.com'],
        'metadata' => ['type' => 'plan_subscription', 'plan_slug' => 'growth', 'interval' => 'month', 'tenant_id' => $tenant->id],
    ]);

    Mail::assertQueued(PaymentReceiptMail::class, fn (PaymentReceiptMail $mail): bool => $mail->amountDisplay === 'GHS 99.00'
        && $mail->description === 'Growth plan, billed monthly'
        && $mail->activePlan === 'Growth'
        && $mail->paymentMethod === 'Mobile money');
});

test('an invoice payment receipt names the invoice', function (): void {
    [$tenant] = billingEmailTenant();
    $invoice = Invoice::factory()->create(['tenant_id' => $tenant->id, 'number' => 'INV-2026-0042', 'status' => Invoice::STATUS_ISSUED]);

    billingWebhook('charge.success', [
        'reference' => 'ref_receipt_invoice',
        'amount' => 12050,
        'currency' => 'GHS',
        'customer' => ['email' => 'owner@example.com'],
        'metadata' => ['type' => 'invoice_payment', 'invoice_id' => $invoice->id, 'tenant_id' => $tenant->id],
    ]);

    Mail::assertQueued(PaymentReceiptMail::class, fn (PaymentReceiptMail $mail): bool => $mail->description === 'Invoice INV-2026-0042'
        && $mail->amountDisplay === 'GHS 120.50');
});

test('a payment seen by both the browser and the webhook sends one receipt and no separate plan email', function (): void {
    [$tenant, $owner] = billingEmailTenant();
    app()->instance(PaymentGateway::class, new PaystackGateway(['secret_key' => 'sk_one_account']));
    $host = 'acme.'.mb_ltrim((string) config('session.domain'), '.');
    $charge = [
        'id' => 99887766,
        'reference' => 'ref_receipt_both',
        'status' => 'success',
        'amount' => 9900,
        'currency' => 'GHS',
        'customer' => ['email' => 'owner@example.com'],
        'metadata' => ['source' => 'miconvener', 'type' => 'plan_subscription', 'plan_slug' => 'growth', 'interval' => 'month', 'tenant_id' => $tenant->id],
    ];
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => $charge])]);

    $this->actingAs($owner)->get("http://{$host}/billing/callback?reference=ref_receipt_both", ['HTTP_HOST' => $host])->assertRedirect();
    billingWebhook('charge.success', $charge);

    Mail::assertQueuedCount(1);
    Mail::assertQueued(PaymentReceiptMail::class, fn (PaymentReceiptMail $mail): bool => $mail->reference === 'ref_receipt_both');
});

test('a charge that fulfils nothing sends no receipt', function (): void {
    [$tenant] = billingEmailTenant();

    billingWebhook('charge.success', [
        'reference' => 'ref_receipt_bad_pack',
        'amount' => 500,
        'currency' => 'USD',
        'customer' => ['email' => 'owner@example.com'],
        'metadata' => ['type' => 'llm_token_purchase', 'pack_key' => 'no-such-pack', 'tenant_id' => $tenant->id],
    ]);

    Mail::assertNothingQueued();
});

test('the receipt renders in the branded layout with the payment details', function (): void {
    [$tenant] = billingEmailTenant();

    $html = (new PaymentReceiptMail($tenant, 'Growth plan, billed monthly', 'GHS 99.00', 'ref_render', '15 September 2026', 'Mobile money', 'Growth', 'https://acme.example.test/billing'))->render();

    expect($html)->toContain('miconvener@2x.png')
        ->toContain('GHS 99.00')
        ->toContain('Growth plan, billed monthly')
        ->toContain('ref_render')
        ->toContain('Mobile money')
        ->toContain('plan is active');
});
