<?php

declare(strict_types=1);

use App\Enum\DuesChargeStatusEnum;
use App\Enum\LedgerCategoryEnum;
use App\Models\CommunitySettings;
use App\Models\DuesCharge;
use App\Models\Member;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Dues\DuesReport;
use App\Services\Finance\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    [$this->tenant, $this->owner] = eventHost('acme');
    $this->tenant->features()->create(['feature_key' => 'paid_tickets', 'enabled' => false]);
    Artisan::call('community:enable', ['tenant' => 'acme']);
    $this->host = eventSubdomainHost('acme');
    $this->actingAs($this->owner)->withServerVariables(['HTTP_HOST' => $this->host]);
});

function financeCharge(Tenant $tenant, array $attributes = []): DuesCharge
{
    $member = Member::factory()->create(['tenant_id' => $tenant->id]);

    return DuesCharge::factory()->create($attributes + [
        'tenant_id' => $tenant->id, 'member_id' => $member->id,
        'membership_type_id' => $member->membership_type_id,
        'status' => DuesChargeStatusEnum::Paid, 'payment_method' => 'online',
        'paid_at' => '2026-10-15 12:00:00', 'amount' => 1000,
        'charged_amount' => 1000, 'gateway_fee_amount' => 30, 'commission_amount' => 20,
    ]);
}

test('dues only finance separates stored collections cash and current stocks by currency and tenant', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
    financeCharge($this->tenant);
    financeCharge($this->tenant, ['payment_method' => 'cash', 'amount' => 500, 'charged_amount' => null, 'gateway_fee_amount' => null, 'commission_amount' => null]);
    financeCharge($this->tenant, ['currency' => 'USD', 'amount' => 2000, 'charged_amount' => 1900, 'gateway_fee_amount' => 0, 'commission_amount' => 0]);
    financeCharge($this->tenant, ['paid_at' => '2026-09-01', 'amount' => 99999]);
    financeCharge($this->tenant, ['status' => DuesChargeStatusEnum::Open, 'due_on' => '2026-10-14', 'amount' => 700]);
    financeCharge($this->tenant, ['status' => DuesChargeStatusEnum::Open, 'due_on' => '2026-10-15', 'amount' => 900]);
    financeCharge(Tenant::factory()->create(), ['amount' => 99999]);
    $this->get("http://{$this->host}/finance?from=2026-10-01&to=2026-10-31")
        ->assertOk()->assertInertia(fn ($page) => $page
        ->where('dues.show_ticket_finance', false)
        ->where('dues.currencies.GHS.collected_online_gross', 1000)
        ->where('dues.currencies.GHS.recorded_cash', 500)
        ->where('dues.currencies.GHS.collected_total', 1500)
        ->where('dues.currencies.GHS.net_online', 950)
        ->where('dues.currencies.GHS.gateway_fees', 30)
        ->where('dues.currencies.GHS.commission', 20)
        ->where('dues.currencies.GHS.arrears_total', 700)
        ->where('dues.currencies.GHS.members_in_arrears', 1)
        ->where('dues.currencies.GHS.available_to_pay_out', 0)
        ->where('dues.currencies.USD.collected_online_gross', 1900));
});

test('organisation local paid dates and default range are used', function () {
    CommunitySettings::for($this->tenant)->fill(['timezone' => 'America/New_York'])->save();
    financeCharge($this->tenant, ['paid_at' => '2026-10-02 03:59:59']);
    financeCharge($this->tenant, ['paid_at' => '2026-10-01 03:59:59']);
    $this->get("http://{$this->host}/finance?from=2026-10-01&to=2026-10-01")
        ->assertInertia(fn ($page) => $page->where('dues.currencies.GHS.collected_total', 1000)->where('dues.timezone', 'America/New_York'));
});

test('invalid report dates are rejected', function (array $query, string $field) {
    $this->get("http://{$this->host}/finance?".http_build_query($query))->assertSessionHasErrors($field);
})->with([
    [['from' => '2026-02-30'], 'from'],
    [['from' => '2026-10-20', 'to' => '2026-10-01'], 'to'],
    [['to' => ['bad']], 'to'],
]);

test('cash only history keeps reports accessible after community is off', function () {
    financeCharge($this->tenant, ['payment_method' => 'cash']);
    Artisan::call('community:enable', ['tenant' => 'acme', '--off' => true]);
    $this->get("http://{$this->host}/finance?from=2026-10-01&to=2026-10-31")->assertOk()
        ->assertInertia(fn ($page) => $page->where('dues.currencies.GHS.available_to_pay_out', 0));
    $this->get("http://{$this->host}/community/money/dues")->assertNotFound();
});

test('dues exports stream literal quoted cells and preserve historical types and currency rows', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
    $charge = financeCharge($this->tenant, ['payment_reference' => '=SUM(1,2)']);
    $charge->member->update(['name' => '=HYPERLINK("evil")', 'phone' => '+233123456789', 'email' => 'literal@example.com']);
    $url = "http://{$this->host}/finance/dues/collections/export?from=2026-10-01&to=2026-10-31";
    $response = $this->get($url)->assertOk()->assertDownload();
    $csv = $response->streamedContent();
    $rows = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), explode("\n", mb_trim($csv)));
    expect($rows[0])->toBe(['Member', 'Phone', 'Email', 'Membership type', 'Period start', 'Period end', 'Currency', 'Amount', 'Method', 'Paid at', 'Reference', 'Gateway fee', 'Commission', 'Net']);
    expect($rows[1][0])->toBe('\'=HYPERLINK("evil")')->and($rows[1][1])->toBe("'+233123456789")->and($rows[1][10])->toBe("'=SUM(1,2)");
    foreach (['GHS', 'USD'] as $currency) {
        financeCharge($this->tenant, ['member_id' => $charge->member_id, 'status' => DuesChargeStatusEnum::Open, 'due_on' => '2026-10-14', 'currency' => $currency, 'period_start' => $currency === 'GHS' ? '2026-08-01' : '2026-09-01']);
    }
    $arrears = $this->get("http://{$this->host}/finance/dues/arrears/export")->assertOk()->streamedContent();
    expect($arrears)->toContain('Currency')->toContain('GHS')->toContain('USD')->toContain('1000');
});

test('finance permission protects both reports and exports', function (string $path) {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($user);
    $this->actingAs($user)->get("http://{$this->host}{$path}")->assertForbidden();
})->with(['/finance', '/finance/dues/collections/export', '/finance/dues/arrears/export']);

test('dues only organisations can configure paystack but cannot connect stripe', function () {
    $this->get("http://{$this->host}/settings/payments")->assertOk()
        ->assertInertia(fn ($page) => $page->where('ticketPaymentsAvailable', false)->where('canSetPlatformFee', false));
    $this->post("http://{$this->host}/settings/payments", ['provider' => 'paystack', 'api_key' => 'sk_test_isolated', 'is_active' => true])->assertSessionHasNoErrors();
    expect($this->tenant->paymentGateways()->where('provider', 'paystack')->first()?->api_key_encrypted)->toBe('sk_test_isolated');
    $this->get("http://{$this->host}/settings/payments/stripe/connect")->assertForbidden();
});

test('historical platform balances remain available after switching to own gateway', function () {
    $charge = financeCharge($this->tenant);
    $ledger = app(LedgerService::class);
    $ledger->recordDuesPayment($this->tenant, $charge, 1000, 20, 'historical-platform', 30);
    $account = App\Models\TenantPayoutAccount::factory()->create(['tenant_id' => $this->tenant->id]);
    App\Models\EventPayout::factory()->create([
        'tenant_id' => $this->tenant->id, 'event_id' => null, 'category' => LedgerCategoryEnum::Dues,
        'payout_account_id' => $account->id, 'currency' => 'GHS', 'amount' => 200,
        'status' => App\Models\EventPayout::STATUS_AWAITING_OTP,
    ]);
    $this->tenant->update(['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    $summary = app(DuesReport::class)->summary($this->tenant, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
    expect($summary['GHS']['collected_total'])->toBe(0)->and($summary['GHS']['available_to_pay_out'])->toBe(750)->and($summary['GHS']['in_flight'])->toBe(200);
});

test('fresh own gateway reports stored nullable legacy receipts without inventing fees', function () {
    $this->tenant->update(['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    financeCharge($this->tenant, ['charged_amount' => null, 'gateway_fee_amount' => null, 'commission_amount' => null]);
    $this->get("http://{$this->host}/finance?from=2026-10-01&to=2026-10-31")->assertInertia(fn ($page) => $page
        ->where('dues.currencies.GHS.collected_online_gross', 1000)->where('dues.currencies.GHS.net_online', 1000)
        ->where('dues.currencies.GHS.available_to_pay_out', 0));
});

test('ticket only finance keeps its exact page props in either settlement mode', function (string $mode) {
    Artisan::call('community:enable', ['tenant' => 'acme', '--off' => true]);
    $this->tenant->features()->where('feature_key', 'paid_tickets')->update(['enabled' => true]);
    $this->tenant->update(['settlement_mode' => $mode]);
    $this->get("http://{$this->host}/finance")->assertInertia(fn ($page) => $page
        ->missing('dues')->where('stats', ['total_volume' => 0, 'transaction_count' => 0, 'refund_volume' => 0])
        ->where('filters', [])->where('transactions.first_page_url', "http://{$this->host}/finance?page=1")
        ->where('transactions.data', []));
})->with([Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT, Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);

test('export includes only paid receipts within dates and this tenant', function () {
    financeCharge($this->tenant, ['payment_reference' => 'included']);
    financeCharge($this->tenant, ['payment_reference' => 'too-old', 'paid_at' => '2026-09-01']);
    financeCharge($this->tenant, ['payment_reference' => 'not-paid', 'status' => DuesChargeStatusEnum::Void]);
    financeCharge(Tenant::factory()->create(), ['payment_reference' => 'foreign']);
    $csv = $this->get("http://{$this->host}/finance/dues/collections/export?from=2026-10-01&to=2026-10-31")->assertOk()->streamedContent();
    expect($csv)->toContain('included')->not->toContain('too-old')->not->toContain('not-paid')->not->toContain('foreign');
});

test('recorded offline cash is a collection and never writes a ledger or increases payable', function () {
    $charge = financeCharge($this->tenant, ['status' => DuesChargeStatusEnum::Open, 'paid_at' => null]);
    app(App\Services\Dues\DuesCharges::class)->recordOffline($charge, (int) $this->owner->id, 'Cash received');
    $this->get("http://{$this->host}/finance?from=".now()->startOfMonth()->toDateString().'&to='.now()->toDateString())
        ->assertInertia(fn ($page) => $page->where('dues.currencies.GHS.recorded_cash', 1000)->where('dues.currencies.GHS.collected_total', 1000)->where('dues.currencies.GHS.available_to_pay_out', 0));
    expect(App\Models\LedgerTransaction::query()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

test('overview uses recorded online amounts and organisation local month boundaries', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
    CommunitySettings::for($this->tenant)->fill(['timezone' => 'America/New_York'])->save();
    financeCharge($this->tenant, ['amount' => 2000, 'charged_amount' => 1900]);
    financeCharge($this->tenant, ['paid_at' => '2026-10-01 03:59:59']);
    $this->get("http://{$this->host}/community")->assertInertia(fn ($page) => $page->where('collectedThisMonth.0.amount', 1900));
});

test('an unrelated tenant cannot use dues exports or configure payment settings', function () {
    Artisan::call('community:enable', ['tenant' => 'acme', '--off' => true]);
    $this->get("http://{$this->host}/finance/dues/collections/export")->assertForbidden();
    $this->get("http://{$this->host}/settings/payments")->assertForbidden();
    $this->post("http://{$this->host}/settings/payments", ['provider' => 'paystack', 'api_key' => 'sk_test'])->assertForbidden();
});

test('arrears exports use historical types and aggregate per member and currency excluding advance and void charges', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
    $charge = financeCharge($this->tenant, ['status' => DuesChargeStatusEnum::Open, 'due_on' => '2026-09-01', 'period_start' => '2026-09-01']);
    $charge->membershipType->update(['name' => 'Historical type']);
    $newType = App\Models\MembershipType::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Current type']);
    $charge->member->update(['membership_type_id' => $newType->id]);
    financeCharge($this->tenant, ['member_id' => $charge->member_id, 'membership_type_id' => $charge->membership_type_id, 'status' => DuesChargeStatusEnum::Open, 'due_on' => '2026-10-01', 'period_start' => '2026-10-01']);
    financeCharge($this->tenant, ['member_id' => $charge->member_id, 'status' => DuesChargeStatusEnum::Open, 'due_on' => '2026-08-01', 'period_start' => '2026-08-01', 'currency' => 'USD']);
    financeCharge($this->tenant, ['status' => DuesChargeStatusEnum::Open, 'due_on' => '2026-10-16', 'amount' => 99999]);
    financeCharge($this->tenant, ['status' => DuesChargeStatusEnum::Void, 'due_on' => '2026-01-01', 'amount' => 88888]);
    $csv = $this->get("http://{$this->host}/finance/dues/arrears/export?from=2025-01-01&to=2025-01-01")->assertOk()->streamedContent();
    expect($csv)->toContain('Historical type')->not->toContain('Current type')->not->toContain('99999')->not->toContain('88888');
    $rows = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), explode("\n", mb_trim($csv)));
    expect($rows)->toHaveCount(3)->and($rows[1][4])->toBe('GHS')->and($rows[1][5])->toBe('2')->and($rows[1][6])->toBe('2000')->and($rows[1][7])->toBe('2026-09-01');
});

test('overview does not expose finance collections or finance links without read finance', function () {
    $charge = financeCharge($this->tenant);
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $user->givePermissionTo('manage members');
    $this->tenant->users()->attach($user);
    $this->actingAs($user)->get("http://{$this->host}/community")->assertInertia(fn ($page) => $page->where('canReadFinance', false)->where('collectedThisMonth', []));
});

test('dues payment settings retain staff permission and secret protection', function () {
    $this->post("http://{$this->host}/settings/payments", ['provider' => 'paystack', 'api_key' => 'sk_test_private', 'public_key' => 'pk_test_public'])->assertSessionHasNoErrors();
    $this->get("http://{$this->host}/settings/payments")->assertInertia(fn ($page) => $page->where('gateways.paystack.public_key', 'pk_test_public')->missing('gateways.paystack.api_key'));
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $user->givePermissionTo('read finance');
    $this->tenant->users()->attach($user);
    $this->actingAs($user)->get("http://{$this->host}/settings/payments")->assertForbidden();
    $this->post("http://{$this->host}/settings/payments", ['provider' => 'paystack', 'api_key' => 'sk_test_other'])->assertForbidden();
});
