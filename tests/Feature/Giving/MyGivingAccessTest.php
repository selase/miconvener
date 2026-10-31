<?php

declare(strict_types=1);

use App\Models\GivingGift;
use App\Models\Member;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Giving\GivingIdentity;
use Tests\Support\GivingScenario;

function myGivingBase(): string
{
    return 'http://'.mb_ltrim((string) config('session.domain'), '.').'/my';
}
function myGivingProof(string $email = 'giver@example.test'): array
{
    return [PlatformAttendeeVerification::SESSION_KEY => ['email' => $email, 'expires_at' => now()->addHour()->timestamp]];
}

test('verified non-member email sees only its own contributions without needing dues or rollout flags', function () {
    [$tenant,, $fund] = GivingScenario::make();
    $mine = GivingGift::factory()->create(['tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'status' => 'completed', 'source' => 'recorded_cash', 'payer_email' => 'giver@example.test', 'payer_email_hash' => GivingIdentity::emailKey($tenant, 'giver@example.test'), 'payer_phone' => '+233241234567', 'collected_at' => now()]);
    GivingGift::factory()->create(['tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'payer_email' => 'other@example.test', 'payer_phone' => $mine->payer_phone, 'status' => 'completed']);
    $tenant->features()->where('feature_key', 'community_giving')->update(['enabled' => false]);
    $this->withSession(myGivingProof())->getJson(myGivingBase().'/giving')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(1, 'giving.data')->assertJsonPath('giving.data.0.id', $mine->id)->assertDontSee('other@example.test');
    $this->getJson(myGivingBase().'/giving?email=other@example.test')->assertJsonCount(1, 'giving.data');
    $this->get(myGivingBase().'/giving/gift/'.$mine->id.'/receipt')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->withSession(myGivingProof('other@example.test'))->get(myGivingBase().'/giving/gift/'.$mine->id.'/receipt')->assertNotFound();
    $this->flushSession();
    $this->getJson(myGivingBase().'/giving')->assertUnauthorized();
});

test('phone alone reveals no gift and a representative grant exposes only attributed records without payer receipts', function () {
    [$tenant,, $fund] = GivingScenario::make();
    $member = Member::factory()->create(['tenant_id' => $tenant->id, 'email' => 'member@example.test', 'phone' => '+233241234567']);
    $gift = GivingGift::factory()->create(['tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'member_id' => $member->id, 'status' => 'completed', 'source' => 'recorded_cash', 'payer_email' => 'private-payer@example.test', 'payer_email_hash' => GivingIdentity::emailKey($tenant, 'private-payer@example.test'), 'collected_at' => now()]);
    $session = app('session.store');
    $session->put(App\Services\Community\PhoneVerification::SESSION_KEY, ['phone' => '+233241234567', 'expires_at' => now()->addHour()->timestamp]);
    $this->withSession($session->all())->getJson(myGivingBase().'/giving')->assertOk()->assertJsonPath('giving.data', []);
    app(App\Services\Community\MemberAccess::class)->issue($session, $member, $member->phone, 'representative', null, 'approved_claim');
    $this->withSession($session->all())->getJson(myGivingBase().'/giving')->assertOk()->assertJsonCount(1, 'giving.data')->assertJsonPath('giving.data.0.access_role', 'representative')->assertJsonPath('giving.data.0.receipt_url', null)->assertDontSee('private-payer@example.test');
    $this->get(myGivingBase().'/giving/gift/'.$gift->id.'/receipt')->assertNotFound();
    $member->increment('access_version');
    $this->getJson(myGivingBase().'/giving')->assertOk()->assertJsonPath('giving.data', []);
});

test('guest receipt capability opens one receipt but never grants contact history', function () {
    [$tenant,,,$attempt] = GivingScenario::paid();
    $this->withSession(app('session.store')->all())->get('http://'.eventSubdomainHost($tenant->slug).'/giving/payments/'.$attempt->reference.'/receipt')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->getJson(myGivingBase().'/giving')->assertUnauthorized();
    $this->flushSession();
    $this->get('http://'.eventSubdomainHost($tenant->slug).'/giving/payments/'.$attempt->reference.'/receipt')->assertNotFound();
});

test('changing verified identity invalidates a prior browser receipt capability', function () {
    app('session.store')->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => 'giver@example.test', 'expires_at' => now()->addHour()->timestamp]);
    [$tenant,,,$attempt] = GivingScenario::paid();
    $proof = app('session.store')->all();
    $proof[PlatformAttendeeVerification::SESSION_KEY] = ['email' => 'different@example.test', 'expires_at' => now()->addHour()->timestamp];
    $this->withSession($proof)->get('http://'.eventSubdomainHost($tenant->slug).'/giving/payments/'.$attempt->reference.'/receipt')->assertNotFound();
});

test('giving only identities can use the native app when opted in and private history when it is off', function () {
    [$tenant,,$fund] = GivingScenario::make();
    GivingGift::factory()->create(['tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'status' => 'completed', 'payer_email' => 'giver@example.test', 'payer_email_hash' => GivingIdentity::emailKey($tenant, 'giver@example.test'), 'collected_at' => now()]);
    $tenant->features()->create(['feature_key' => 'community_app', 'enabled' => true]);
    $this->withSession(myGivingProof())->get(myGivingBase())->assertOk()->assertInertia(fn ($page) => $page->where('app_shell_enabled', true));
    $tenant->features()->where('feature_key', 'community_app')->update(['enabled' => false]);
    $this->get(myGivingBase().'/giving')->assertOk()->assertInertia(fn ($page) => $page->component('Public/Giving/History')->has('giving.data', 1));
});

test('a contactless guest can open the result and download the same receipt more than once', function () {
    [$tenant,,,$attempt] = GivingScenario::paid();
    $base = 'http://'.eventSubdomainHost($tenant->slug).'/giving/payments/'.$attempt->reference;
    $this->withSession(app('session.store')->all())->getJson($base)->assertOk()->assertJsonPath('status', 'confirmed');
    $this->get($base.'/receipt')->assertOk();
    $this->get($base.'/receipt')->assertOk();
});

test('only new Giving event refunds keep their original verified payer receipt available', function () {
    [$tenant] = GivingScenario::online();
    $event = App\Models\Event::factory()->published()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS', 'allow_contributions' => true]);
    App\Models\CommunitySettings::withoutGlobalScopes()->updateOrCreate(['tenant_id' => $tenant->id], ['giving_event_cutover_at' => now()->subMinute()]);
    app(App\Services\Events\EventContributionService::class)->initiateContribution($event, ['contributor_name' => 'Giver', 'contributor_email' => 'giver@example.test', 'amount' => '100'], '/callback/:id');
    $attempt = App\Models\GivingPaymentAttempt::withoutGlobalScopes()->whereNotNull('event_contribution_id')->sole();
    app(App\Services\Giving\GivingPaymentConfirmer::class)->confirm($attempt->reference, GivingScenario::verified($attempt, ['metadata' => ['source_type' => 'event_contribution', 'source_id' => $attempt->event_contribution_id]]));
    $source = App\Models\EventContribution::withoutGlobalScopes()->findOrFail($attempt->event_contribution_id);
    $source->update(['status' => 'refunded']);
    $url = myGivingBase().'/contributions/'.$source->id.'/receipt';
    $this->withSession(myGivingProof())->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    App\Models\GivingContributionLink::withoutGlobalScopes()->where('event_contribution_id', $source->id)->update(['category' => 'event', 'origin' => 'legacy_association']);
    $this->get($url)->assertStatus(422);
});

test('a no-dues membership gets giving without creating charges and scoped session keys change on revocation', function () {
    [$tenant,,$fund] = GivingScenario::make();
    $type = App\Models\MembershipType::factory()->create(['tenant_id' => $tenant->id, 'amount' => null, 'period' => null]);
    $member = Member::factory()->create(['tenant_id' => $tenant->id, 'membership_type_id' => $type->id, 'email' => 'giver@example.test']);
    GivingGift::factory()->create(['tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'member_id' => $member->id, 'source' => 'recorded_cash', 'status' => 'completed', 'collected_at' => now(), 'payer_email' => 'giver@example.test', 'payer_email_hash' => GivingIdentity::emailKey($tenant, 'giver@example.test')]);
    $this->withSession(myGivingProof())->getJson(myGivingBase().'/money')->assertOk()->assertJsonPath('dues.data.0.has_dues', false)->assertJsonCount(1, 'giving.data');
    $first = $this->getJson(myGivingBase().'/giving/session')->assertOk()->json('access_key');
    $member->update(['email' => 'replacement@example.test']);
    $second = $this->getJson(myGivingBase().'/giving/session')->assertOk()->json('access_key');
    expect($first)->not->toBe($second)->and(App\Models\DuesCharge::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('typed giving union paginates once without duplicate gifts and applies source filters', function () {
    [$tenant,,$fund] = GivingScenario::make();
    GivingGift::factory()->count(21)->create(['tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'status' => 'completed', 'source' => 'recorded_cash', 'collected_at' => now(), 'payer_email' => 'giver@example.test', 'payer_email_hash' => GivingIdentity::emailKey($tenant, 'giver@example.test')]);
    $event = App\Models\Event::factory()->published()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);
    $source = App\Models\EventContribution::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'contributor_name' => 'Giver', 'contributor_email' => 'giver@example.test', 'amount' => 10000, 'currency' => 'GHS', 'status' => 'completed', 'paid_at' => now(), 'payment_reference' => 'history_typed_union']);
    app(App\Services\Giving\GivingContributionBridge::class)->associate($tenant, true);
    $first = $this->withSession(myGivingProof())->getJson(myGivingBase().'/giving')->assertOk()->assertJsonPath('giving.total', 22)->assertJsonCount(20, 'giving.data')->json('giving.data');
    $second = $this->getJson(myGivingBase().'/giving?giving_page=2')->assertOk()->assertJsonCount(2, 'giving.data')->json('giving.data');
    expect(array_intersect(array_column($first, 'key'), array_column($second, 'key')))->toBe([]);
    $this->getJson(myGivingBase().'/giving?source_type=event_contribution')->assertOk()->assertJsonCount(1, 'giving.data')->assertJsonPath('giving.data.0.id', $source->id);
    $this->getJson(myGivingBase().'/giving?source=bulk_cash')->assertOk()->assertJsonPath('giving.data', []);
});

test('a new Giving event callback can finish an in-flight payment after its event is deleted', function () {
    [$tenant] = GivingScenario::online();
    $event = App\Models\Event::factory()->published()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS', 'allow_contributions' => true]);
    App\Models\CommunitySettings::withoutGlobalScopes()->updateOrCreate(['tenant_id' => $tenant->id], ['giving_event_cutover_at' => now()->subMinute()]);
    app(App\Services\Events\EventContributionService::class)->initiateContribution($event, ['contributor_name' => 'Guest', 'amount' => '100'], '/callback/:id');
    $attempt = App\Models\GivingPaymentAttempt::withoutGlobalScopes()->whereNotNull('event_contribution_id')->sole();
    Illuminate\Support\Facades\Http::fake(['*api.paystack.co/transaction/verify/*' => Illuminate\Support\Facades\Http::response(['status' => true, 'data' => GivingScenario::verified($attempt, ['id' => 777, 'metadata' => ['source_type' => 'event_contribution', 'source_id' => $attempt->event_contribution_id]])])]);
    $event->delete();
    $this->withSession(app('session.store')->all())->get('http://'.eventSubdomainHost($tenant->slug).'/e/'.$event->slug.'/contributions/'.$attempt->event_contribution_id.'/callback')->assertRedirect();
    expect($attempt->fresh()->review_reason)->toBeNull();
    expect($attempt->fresh()->state)->toBe('confirmed');
});
