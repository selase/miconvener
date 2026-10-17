<?php

declare(strict_types=1);
use App\Models\GivingAdjustment;
use App\Models\GivingGift;
use App\Services\Giving\GivingIdentity;
use Tests\Support\GivingScenario;

test('personal statements use local dates separate currencies and dated corrections with no gift debt', function () {
    [$tenant,,$fund] = GivingScenario::make();
    App\Models\CommunitySettings::withoutGlobalScopes()->updateOrCreate(['tenant_id' => $tenant->id], ['timezone' => 'America/New_York']);
    $gift = GivingGift::factory()->create(['tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'status' => 'completed', 'source' => 'recorded_cash', 'amount' => 10000, 'payer_email' => 'giver@example.test', 'payer_email_hash' => GivingIdentity::emailKey($tenant, 'giver@example.test'), 'collected_at' => '2026-10-10 03:30:00']);
    GivingAdjustment::factory()->create(['tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'gift_id' => $gift->id, 'kind' => 'cash_correction', 'status' => 'confirmed', 'principal' => 1000, 'confirmed_at' => '2026-10-11 12:00:00']);
    $base = 'http://'.mb_ltrim((string) config('session.domain'), '.').'/my/giving/statement';
    $proof = [App\Services\Events\PlatformAttendeeVerification::SESSION_KEY => ['email' => 'giver@example.test', 'expires_at' => now()->addHour()->timestamp]];
    $csv = $this->withSession($proof)->get($base.'?tenant_slug='.$tenant->slug.'&from=2026-10-09&to=2026-10-09&format=csv')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->streamedContent();
    expect($csv)->toContain('10000')->not->toContain('1000,');
    $later = $this->get($base.'?tenant_slug='.$tenant->slug.'&from=2026-10-11&to=2026-10-11&format=csv')->assertOk()->streamedContent();
    expect($later)->toContain('cash_correction', '-1000')->not->toContain('10000');
    $this->get($base.'?tenant_slug='.$tenant->slug.'&from=2026-10-09&to=2026-10-11')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->getJson($base.'?from=2024-01-01&to=2026-10-11')->assertUnprocessable();
    $this->getJson(str_replace('/statement', '', $base))->assertOk()->assertJsonPath('giving.data.0.net_contribution', 9000)->assertJsonPath('giving.data.0.adjusted_amount', 1000);
});

test('historical event sources retain their currency when the event now uses another currency', function () {
    [$tenant] = GivingScenario::make();
    $event = App\Models\Event::factory()->published()->create(['tenant_id' => $tenant->id, 'currency' => 'USD']);
    $legacy = App\Models\EventContribution::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'contributor_name' => 'Giver', 'contributor_email' => 'giver@example.test', 'amount' => 10000, 'currency' => 'GHS', 'status' => 'completed', 'paid_at' => now(), 'payment_reference' => 'history_currency']);
    app(App\Services\Giving\GivingContributionBridge::class)->associate($tenant, true);
    $fund = App\Models\GivingContributionLink::withoutGlobalScopes()->where('event_contribution_id', $legacy->id)->sole()->fund;
    expect($fund->currency)->toBe('GHS');
});

test('representative statements label their scope without exposing the original payer', function () {
    [$tenant,,$fund] = GivingScenario::make();
    $member = App\Models\Member::factory()->create(['tenant_id' => $tenant->id, 'email' => 'member@example.test', 'phone' => '+233241234567']);
    GivingGift::factory()->create(['tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'member_id' => $member->id, 'status' => 'completed', 'source' => 'recorded_cash', 'collected_at' => now(), 'payer_email' => 'private-payer@example.test']);
    $session = app('session.store');
    $session->put(App\Services\Community\PhoneVerification::SESSION_KEY, ['phone' => $member->phone, 'expires_at' => now()->addHour()->timestamp]);
    app(App\Services\Community\MemberAccess::class)->issue($session, $member, $member->phone, 'representative', null, 'approved_claim');
    $base = 'http://'.mb_ltrim((string) config('session.domain'), '.').'/my/giving/statement';
    $csv = $this->withSession($session->all())->get($base.'?from='.now()->startOfMonth()->toDateString().'&to='.now()->endOfMonth()->toDateString().'&format=csv')->assertOk()->streamedContent();
    expect($csv)->toContain('representative', $member->name)->not->toContain('private-payer@example.test');
});

test('personal statements flag legacy refunds with unknown dates instead of inventing a dated adjustment', function () {
    [$tenant] = GivingScenario::make();
    $event = App\Models\Event::factory()->published()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);
    App\Models\EventContribution::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'contributor_name' => 'Giver', 'contributor_email' => 'giver@example.test', 'amount' => 10000, 'currency' => 'GHS', 'status' => 'refunded', 'paid_at' => '2026-10-01 12:00:00', 'payment_reference' => 'undated_personal']);
    app(App\Services\Giving\GivingContributionBridge::class)->associate($tenant, true);
    $session = app('session.store');
    $session->put(App\Services\Events\PlatformAttendeeVerification::SESSION_KEY, ['email' => 'giver@example.test', 'expires_at' => now()->addHour()->timestamp]);
    $rows = app(App\Services\Giving\GivingHistory::class)->statement($session, ['from' => '2026-10-01', 'to' => '2026-10-31']);
    $note = $rows->firstWhere('kind', 'legacy_refund_undated');
    expect($note)->not->toBeNull()->and($note['date'])->toBe('Unknown')->and($note['amount'])->toBe(0)->and($note['undated_amount'])->toBe(10000);
});

test('associating historical contributions includes deleted events without changing their records', function () {
    [$tenant] = GivingScenario::make();
    $event = App\Models\Event::factory()->published()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);
    $source = App\Models\EventContribution::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'contributor_name' => 'Giver', 'contributor_email' => 'giver@example.test', 'amount' => 10000, 'currency' => 'GHS', 'status' => 'completed', 'paid_at' => now(), 'payment_reference' => 'deleted_event_history']);
    $before = $source->fresh()->getRawOriginal();
    $event->delete();
    app(App\Services\Giving\GivingContributionBridge::class)->associate($tenant, true);
    expect(App\Models\GivingContributionLink::withoutGlobalScopes()->where('event_contribution_id', $source->id)->count())->toBe(1)->and($source->fresh()->getRawOriginal())->toBe($before);
});
