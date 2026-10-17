<?php

declare(strict_types=1);

use App\Models\CommunitySettings;
use App\Models\Event;
use App\Models\EventContribution;
use App\Models\GivingContributionLink;
use App\Models\GivingGift;
use App\Models\GivingPaymentAttempt;
use App\Services\Events\EventContributionService;
use App\Services\Giving\GivingBalance;
use App\Services\Giving\GivingContributionBridge;
use App\Services\Giving\GivingPaymentConfirmer;
use Carbon\CarbonImmutable;
use Tests\Support\GivingScenario;

function givingCutoverEvent($tenant): Event
{
    return Event::factory()->published()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS', 'allow_contributions' => true, 'contribution_min_amount_pesewas' => 100]);
}

test('cutover is dry by default and compares the persisted initiation instant at the boundary', function () {
    [$tenant] = GivingScenario::make();
    $this->artisan('giving:cutover', ['tenant' => $tenant->slug, '--at' => '2026-10-17T12:00:00Z'])->assertSuccessful();
    expect(CommunitySettings::for($tenant)->giving_event_cutover_at)->toBeNull();
    $this->artisan('giving:cutover', ['tenant' => $tenant->slug, '--at' => '2026-10-17T12:00:00Z', '--apply' => true])->assertSuccessful();
    $bridge = app(GivingContributionBridge::class);
    expect($bridge->usesGiving($tenant, CarbonImmutable::parse('2026-10-17T11:59:59Z')))->toBeFalse()->and($bridge->usesGiving($tenant, CarbonImmutable::parse('2026-10-17T12:00:00Z')))->toBeTrue();
});

test('new cutover event contributions retain one original source and settle in Giving only', function () {
    [$tenant] = GivingScenario::online();
    $event = givingCutoverEvent($tenant);
    CommunitySettings::withoutGlobalScopes()->updateOrCreate(['tenant_id' => $tenant->id], ['giving_event_cutover_at' => now()->subMinute()]);
    $url = app(EventContributionService::class)->initiateContribution($event, ['contributor_name' => 'Guest', 'contributor_email' => 'giver@example.test', 'amount' => '100.00', 'tribute_message' => 'For the project'], '/callback/:id');
    expect($url)->toContain('checkout.paystack.com');
    $source = EventContribution::withoutGlobalScopes()->where('event_id', $event->id)->sole();
    $attempt = GivingPaymentAttempt::withoutGlobalScopes()->where('event_contribution_id', $source->id)->sole();
    $verified = GivingScenario::verified($attempt, ['metadata' => ['source_type' => 'event_contribution', 'source_id' => $source->id]]);
    app(GivingPaymentConfirmer::class)->confirm($attempt->reference, $verified);
    app(GivingPaymentConfirmer::class)->confirm($attempt->reference, $verified);
    expect($source->fresh()->status)->toBe('completed')->and($source->fresh()->tribute_message)->toBe('For the project')->and(GivingGift::withoutGlobalScopes()->where('fund_id', $attempt->fund_id)->count())->toBe(0)->and(app(GivingBalance::class)->payable($tenant, 'GHS'))->toBe(9700)->and($event->ledgerEntries()->count())->toBe(0);
    expect(GivingContributionLink::withoutGlobalScopes()->where('event_contribution_id', $source->id)->sole()->category)->toBe('giving');
});

test('flag off after cutover stops starts while a frozen giving confirmation still completes', function () {
    [$tenant] = GivingScenario::online();
    $event = givingCutoverEvent($tenant);
    CommunitySettings::withoutGlobalScopes()->updateOrCreate(['tenant_id' => $tenant->id], ['giving_event_cutover_at' => now()->subMinute()]);
    app(EventContributionService::class)->initiateContribution($event, ['contributor_name' => 'Guest', 'amount' => '100'], '/callback/:id');
    $attempt = GivingPaymentAttempt::withoutGlobalScopes()->whereNotNull('event_contribution_id')->sole();
    $tenant->features()->where('feature_key', 'community_giving')->update(['enabled' => false]);
    expect(fn () => app(EventContributionService::class)->initiateContribution($event, ['contributor_name' => 'Guest', 'amount' => '100'], '/callback/:id'))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    app(GivingPaymentConfirmer::class)->confirm($attempt->reference, GivingScenario::verified($attempt, ['metadata' => ['source_type' => 'event_contribution', 'source_id' => $attempt->event_contribution_id]]));
    expect($attempt->fresh()->state)->toBe('confirmed');
});

test('event progress counts independently verified givers and legacy refunded sources do not inflate net reports', function () {
    [$tenant] = GivingScenario::online();
    $event = givingCutoverEvent($tenant);
    app('session.store')->put(App\Services\Events\PlatformAttendeeVerification::SESSION_KEY, ['email' => 'giver@example.test', 'expires_at' => now()->addHour()->timestamp]);
    CommunitySettings::withoutGlobalScopes()->updateOrCreate(['tenant_id' => $tenant->id], ['giving_event_cutover_at' => now()->subMinute()]);
    app(EventContributionService::class)->initiateContribution($event, ['contributor_name' => 'Guest', 'contributor_email' => 'giver@example.test', 'amount' => '100'], '/callback/:id');
    $attempt = GivingPaymentAttempt::withoutGlobalScopes()->whereNotNull('event_contribution_id')->sole();
    app(GivingPaymentConfirmer::class)->confirm($attempt->reference, GivingScenario::verified($attempt, ['metadata' => ['source_type' => 'event_contribution', 'source_id' => $attempt->event_contribution_id]]));
    expect(app(App\Services\Giving\GivingProgress::class)->for($attempt->fund)['identified_givers'])->toBe(1);
});

test('legacy pending checkout remains in event after cutover and explicit rollback affects only future starts', function () {
    [$tenant] = GivingScenario::online();
    $event = givingCutoverEvent($tenant);
    $service = app(EventContributionService::class);
    $service->initiateContribution($event, ['contributor_name' => 'Early', 'amount' => '100'], '/callback/:id');
    $legacy = EventContribution::withoutGlobalScopes()->where('event_id', $event->id)->sole();
    CommunitySettings::withoutGlobalScopes()->updateOrCreate(['tenant_id' => $tenant->id], ['giving_event_cutover_at' => now()]);
    $service->confirmContributionPayment('old_provider_ref', ['amount' => 10000, 'currency' => 'GHS', 'fees' => 200, 'metadata' => ['event_contribution_id' => $legacy->id]]);
    expect($legacy->fresh()->status)->toBe('completed')->and($event->ledgerEntries()->count())->toBe(1)->and(GivingPaymentAttempt::withoutGlobalScopes()->where('event_contribution_id', $legacy->id)->count())->toBe(0);
    $service->initiateContribution($event, ['contributor_name' => 'New', 'amount' => '100'], '/callback/:id');
    $giving = GivingPaymentAttempt::withoutGlobalScopes()->whereNotNull('event_contribution_id')->sole();
    $this->artisan('giving:cutover', ['tenant' => $tenant->slug, '--off' => true, '--apply' => true])->assertSuccessful();
    $service->initiateContribution($event, ['contributor_name' => 'Later', 'amount' => '100'], '/callback/:id');
    expect(GivingPaymentAttempt::withoutGlobalScopes()->whereNotNull('event_contribution_id')->count())->toBe(1)->and($giving->fresh()->category)->toBe('giving');
    app(GivingContributionBridge::class)->associate($tenant, true);
    app(GivingContributionBridge::class)->removeLegacyAssociations($tenant);
    expect(GivingContributionLink::withoutGlobalScopes()->where('origin', 'checkout')->count())->toBe(1)->and(EventContribution::withoutGlobalScopes()->where('event_id', $event->id)->count())->toBe(3);
});

test('own Stripe event cutover uses the connected account and creates no platform principal balance', function () {
    [$tenant] = GivingScenario::make();
    $tenant->update(['package_id' => App\Models\Package::factory()->create()->id, 'settlement_mode' => App\Models\Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    $tenant->features()->where('feature_key', 'community_giving')->update(['meta' => ['online_enabled' => true]]);
    config(['services.stripe.secret' => 'sk_test_giving_stripe', 'services.stripe.connect.client_id' => 'ca_giving']);
    App\Models\TenantPaymentGateway::factory()->create(['tenant_id' => $tenant->id, 'provider' => 'stripe', 'provider_account_id' => 'acct_giving', 'api_key_encrypted' => null, 'is_active' => true]);
    $fake = new Tests\Support\GivingStripeHttp;
    Stripe\ApiRequestor::setHttpClient($fake);
    try {
        $event = givingCutoverEvent($tenant);
        CommunitySettings::withoutGlobalScopes()->updateOrCreate(['tenant_id' => $tenant->id], ['giving_event_cutover_at' => now()->subMinute()]);
        $url = app(EventContributionService::class)->initiateContribution($event, ['contributor_name' => 'Stripe guest', 'amount' => '100'], '/callback/:id', 'stripe');
        $attempt = GivingPaymentAttempt::withoutGlobalScopes()->whereNotNull('event_contribution_id')->sole();
        expect($url)->toBe('https://checkout.stripe.com/giving')->and($fake->metadata['source_type'])->toBe('event_contribution');
        app(GivingPaymentConfirmer::class)->confirm($attempt->reference, app(App\Services\Giving\GivingGateways::class)->verify($attempt));
        expect(app(GivingBalance::class)->payable($tenant, 'GHS'))->toBe(0)->and($attempt->fresh()->state)->toBe('confirmed')->and($event->ledgerEntries()->count())->toBe(0);
    } finally {
        Stripe\ApiRequestor::setHttpClient(Stripe\HttpClient\CurlClient::instance());
    }
});

test('anonymous Giving event contacts stay out of event props and exported rows', function () {
    [$tenant,$user] = GivingScenario::online();
    $user->givePermissionTo('read event');
    $event = givingCutoverEvent($tenant);
    CommunitySettings::withoutGlobalScopes()->updateOrCreate(['tenant_id' => $tenant->id], ['giving_event_cutover_at' => now()->subMinute()]);
    app(EventContributionService::class)->initiateContribution($event, ['contributor_name' => 'Secret Giving Payer', 'contributor_email' => 'secret-giving@example.test', 'is_anonymous' => true, 'amount' => '100'], '/callback/:id');
    $host = 'http://'.eventSubdomainHost($tenant->slug);
    $this->actingAs($user)->get($host.'/events/'.$event->id.'/contributions')->assertOk()->assertDontSee('Secret Giving Payer')->assertDontSee('secret-giving@example.test');
    $csv = $this->get($host.'/events/'.$event->id.'/contributions/export')->assertOk()->streamedContent();
    expect($csv)->not->toContain('Secret Giving Payer', 'secret-giving@example.test');
});

test('replaying an event checkout submission reuses its source and provider operation', function () {
    [$tenant] = GivingScenario::online();
    $event = givingCutoverEvent($tenant);
    CommunitySettings::withoutGlobalScopes()->updateOrCreate(['tenant_id' => $tenant->id], ['giving_event_cutover_at' => now()->subMinute()]);
    $input = ['contributor_name' => 'Guest', 'amount' => '100', 'submission_key' => (string) Illuminate\Support\Str::uuid()];
    $service = app(EventContributionService::class);
    $first = $service->initiateContribution($event, $input, '/callback/:id');
    $second = $service->initiateContribution($event, $input, '/callback/:id');
    expect($second)->toBe($first)->and(EventContribution::withoutGlobalScopes()->where('event_id', $event->id)->count())->toBe(1)->and(GivingPaymentAttempt::withoutGlobalScopes()->whereNotNull('event_contribution_id')->count())->toBe(1);
});
