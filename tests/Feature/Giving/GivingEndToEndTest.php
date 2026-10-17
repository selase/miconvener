<?php

declare(strict_types=1);

use App\Enum\LedgerCategoryEnum;
use App\Services\Giving\GivingBalance;
use App\Services\Giving\GivingCash;
use App\Services\Giving\GivingPaymentConfirmer;
use App\Services\Giving\GivingRefunds;
use App\Services\Giving\GivingSources;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\GivingScenario;

test('a community with no dues or members completes giving cash receipts adjustments and OTP settlement', function () {
    Queue::fake();
    [$tenant,$user,$fund] = GivingScenario::make(['state' => 'draft', 'visibility' => 'public']);
    $tenant->update(['package_id' => null, 'settlement_mode' => App\Models\Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT]);
    $tenant->features()->where('feature_key', 'community_giving')->update(['meta' => ['online_enabled' => true]]);
    config(['services.settlement.paystack.secret_key' => 'sk_test_giving', 'services.commission_split.enforced' => false]);
    Http::preventStrayRequests();
    Http::fake(['*api.paystack.co/customer*' => Http::response(['status' => true, 'data' => ['customer_code' => 'CUS_GIVING', 'email' => 'giver@example.test']]), '*api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/giving-test']])]);
    $fund = app(App\Services\Giving\GivingFunds::class)->save($tenant, $user, ['state' => 'open'], $fund);
    app(App\Services\Giving\GivingCheckout::class)->start($fund, ['amount_minor' => 10000, 'currency' => 'GHS', 'payer_email' => 'giver@example.test', 'provider' => 'paystack'], app('session.store'), (string) Str::uuid());
    $attempt = App\Models\GivingPaymentAttempt::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();
    expect($attempt->commission_amount)->toBe(200)->and($tenant->package_id)->toBeNull();
    $user->givePermissionTo(['process refunds', 'manage payouts']);
    app(GivingPaymentConfirmer::class)->confirm($attempt->reference, GivingScenario::verified($attempt));
    $attempt->refresh();
    $gift = app(GivingCash::class)->record($fund, $user, ['source' => 'bulk_cash', 'amount' => 5000, 'currency' => 'GHS'], (string) Str::uuid());
    app(GivingCash::class)->correct($gift, $user, 1000, 'Counting correction', (string) Str::uuid());
    $source = app(GivingSources::class)->find($tenant, 'gift', $attempt->gift_id);
    Http::fake(['*api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 901, 'status' => 'pending']])]);
    $change = app(GivingRefunds::class)->request($source, $user, 1000, 'Giver request', (string) Str::uuid());
    app(GivingRefunds::class)->confirm($change, GivingScenario::refunded($change, $attempt));
    $proof = [App\Services\Events\PlatformAttendeeVerification::SESSION_KEY => ['email' => 'giver@example.test', 'expires_at' => now()->addHour()->timestamp]];
    $base = 'http://'.mb_ltrim((string) config('session.domain'), '.');
    $this->withSession($proof)->getJson($base.'/my/giving?tenant_slug='.$tenant->slug)->assertOk()->assertJsonPath('giving.data.0.net_contribution', 9000);
    $this->get($base.'/my/giving/gift/'.$attempt->gift_id.'/receipt')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(App\Models\GivingReceiptNotice::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(2);
    $available = app(GivingBalance::class)->available($tenant, 'GHS');
    $account = App\Models\TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'is_verified' => true, 'recipient_code' => 'RCP_E2E']);
    $service = app(App\Services\Finance\CommunityPayouts::class);
    $payout = $service->reserve($tenant, $user, LedgerCategoryEnum::Giving, ['payout_account_id' => $account->id, 'currency' => 'GHS', 'amount' => $available]);
    Http::fake(['*api.paystack.co/transfer/finalize_transfer' => Http::response(['status' => true, 'data' => ['status' => 'pending', 'transfer_code' => 'TRF_E2E']]), '*api.paystack.co/transfer' => Http::response(['status' => true, 'data' => ['status' => 'otp', 'transfer_code' => 'TRF_E2E']])]);
    $service->send($tenant, $user, $payout);
    expect($payout->fresh()->status)->toBe('awaiting_otp');
    $tenant->features()->whereIn('feature_key', ['community', 'community_giving'])->update(['enabled' => false]);
    $service->finalize($tenant, $user, $payout, '123456');
    $service->settleTransfer($payout->fresh(), $payout->fresh()->provider_reference);
    expect(app(GivingBalance::class)->available($tenant, 'GHS'))->toBe(0);
    $this->getJson($base.'/my/giving?tenant_slug='.$tenant->slug)->assertOk()->assertJsonCount(1, 'giving.data');
    expect(app(App\Services\Giving\GivingContributionBridge::class)->reconcile($tenant)['discrepancies'])->toBe([]);
    foreach ([App\Models\Member::class, App\Models\MembershipType::class, App\Models\DuesCharge::class, App\Models\Event::class] as $class) {
        expect($class::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
    }
    $this->artisan('finance:verify-categories')->assertSuccessful();
});

test('cash member attribution is separately authorised and never merges distinct payers into the member identity', function () {
    [$tenant,$user,$fund] = GivingScenario::make();
    $member = App\Models\Member::factory()->create(['tenant_id' => $tenant->id, 'email' => 'member@example.test']);
    $user->givePermissionTo('manage members');
    $cash = app(GivingCash::class);
    $input = ['source' => 'recorded_cash', 'amount' => 5000, 'currency' => 'GHS', 'member_id' => $member->id];
    $one = $cash->record($fund, $user, [...$input, 'payer_name' => 'First payer'], (string) Str::uuid());
    $two = $cash->record($fund, $user, [...$input, 'payer_name' => 'Second payer'], (string) Str::uuid());
    expect($one->giver_key)->not->toBe($two->giver_key)->and($one->giver_key)->not->toBe('member:'.$member->id);
    $user->revokePermissionTo('manage members');
    expect(fn () => $cash->record($fund, $user, $input, (string) Str::uuid()))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
});

test('staff member picker is bounded tenant scoped and unavailable without member and finance permissions', function () {
    [$tenant,$user] = GivingScenario::make();
    App\Models\Member::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Volunteer Pilot', 'email' => 'private@example.test']);
    App\Models\Member::factory()->create(['name' => 'Volunteer Other']);
    $host = eventSubdomainHost($tenant->slug);
    $url = 'http://'.$host.'/community/money/giving/member-options?q=Volunteer';
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => $host])->getJson($url)->assertForbidden();
    $user->givePermissionTo('manage members');
    $r = $this->getJson($url)->assertOk()->assertJsonCount(1, 'members')->assertJsonPath('members.0.name', 'Volunteer Pilot');
    expect($r->getContent())->not->toContain('private@example.test');
    $this->getJson(str_replace('Volunteer', 'V', $url))->assertUnprocessable();
});

test('public event checkout options honour giving readiness after cutover without altering legacy contributions', function () {
    [$tenant] = GivingScenario::online();
    $event = App\Models\Event::factory()->published()->create(['tenant_id' => $tenant->id, 'allow_contributions' => true, 'currency' => 'GHS']);
    $host = eventSubdomainHost($tenant->slug);
    $url = 'http://'.$host.'/e/'.$event->slug;
    App\Models\CommunitySettings::withoutGlobalScopes()->updateOrCreate(['tenant_id' => $tenant->id], ['giving_event_cutover_at' => now()->subMinute()]);
    $tenant->features()->where('feature_key', 'community_giving')->update(['enabled' => false]);
    $this->withServerVariables(['HTTP_HOST' => $host])->get($url)->assertInertia(fn ($page) => $page->where('event.contribution_providers', []));
    app(App\Services\Giving\GivingContributionBridge::class)->cutover($tenant, null);
    $this->get($url)->assertInertia(fn ($page) => $page->where('event.contribution_providers', ['paystack']));
});

test('own-account Giving stays outside platform principal and never grants another organisation access', function () {
    Queue::fake();
    [$tenant,$user,,$attempt,$source] = GivingScenario::paid(true);
    expect(app(GivingBalance::class)->payable($tenant, 'GHS'))->toBe(-$attempt->commission_amount)->and(app(GivingBalance::class)->available($tenant, 'GHS'))->toBe(0);
    [$other] = GivingScenario::make();
    setPermissionsTeamId($tenant->id);
    expect(fn () => app(GivingSources::class)->find($other, 'gift', $source->id))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
    $user->revokePermissionTo('process refunds');
    expect(fn () => app(GivingRefunds::class)->request($source, $user, 1000, 'Restricted', (string) Str::uuid()))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
    expect(App\Models\Member::withoutGlobalScopes()->count())->toBe(0)->and(App\Models\DuesCharge::withoutGlobalScopes()->count())->toBe(0);
});
