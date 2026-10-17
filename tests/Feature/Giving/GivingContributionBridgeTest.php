<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventContribution;
use App\Models\GivingContributionLink;
use App\Models\GivingGift;
use App\Services\Giving\GivingContributionBridge;
use Tests\Support\GivingScenario;

function givingLegacyContribution($tenant, $event, array $attributes = []): EventContribution
{
    return EventContribution::query()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'contributor_name' => 'Synthetic payer', 'contributor_email' => 'giver@example.test', 'amount' => 10000, 'currency' => 'GHS', 'status' => 'completed', 'payment_reference' => 'legacy_'.Illuminate\Support\Str::uuid(), 'paid_at' => now(), ...$attributes]);
}

test('association is a bounded idempotent link without rewriting sources ledgers payouts or receipts', function () {
    [$tenant] = GivingScenario::make();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'allow_contributions' => true]);
    $source = givingLegacyContribution($tenant, $event);
    app(App\Services\Finance\LedgerService::class)->recordContribution($event, $source, 10000, 100, $source->payment_reference, 200);
    $registration = App\Models\EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'amount' => 10000]);
    app(App\Services\Finance\LedgerService::class)->recordTicketSale($event, $registration, 10000, 100, 'ticket_history', 200);
    $account = App\Models\TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);
    $payout = App\Models\EventPayout::factory()->paid()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'payout_account_id' => $account->id, 'amount' => 15000, 'provider_reference' => 'mixed_history']);
    app(App\Services\Finance\LedgerService::class)->recordPayout($event, 15000, 'mixed_history', $payout);
    $payoutBefore = $payout->fresh()->getRawOriginal();
    $before = $source->fresh()->getRawOriginal();
    $ledger = App\Models\LedgerTransaction::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('id')->get()->toArray();
    $flat = App\Models\EventLedgerEntry::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('id')->get()->toArray();
    $bridge = app(GivingContributionBridge::class);
    expect($bridge->associate($tenant, false)['unlinked'])->toBe(1)->and(GivingContributionLink::withoutGlobalScopes()->count())->toBe(0);
    $this->artisan('giving:associate-contributions', ['--tenant' => $tenant->slug])->expectsOutput(json_encode(['tenant' => $tenant->slug, 'sources' => 1, 'unlinked' => 1, 'applied' => false], JSON_THROW_ON_ERROR))->assertSuccessful();
    $bridge->associate($tenant, true);
    $bridge->associate($tenant, true);
    expect($source->fresh()->getRawOriginal())->toBe($before)->and(App\Models\LedgerTransaction::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('id')->get()->toArray())->toBe($ledger)->and(App\Models\EventLedgerEntry::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('id')->get()->toArray())->toBe($flat)->and(GivingGift::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
    expect($payout->fresh()->getRawOriginal())->toBe($payoutBefore);
    $link = GivingContributionLink::withoutGlobalScopes()->where('event_contribution_id', $source->id)->sole();
    expect($link->category)->toBe('event')->and($link->origin)->toBe('legacy_association')->and($link->fund->visibility)->toBe('private');
});

test('association command never applies across every organisation by default', function () {
    $this->artisan('giving:associate-contributions', ['--apply' => true])->assertFailed();
});

test('legacy refunds without a recorded refund date are explicit reconciliation exceptions', function () {
    [$tenant] = GivingScenario::make();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $source = givingLegacyContribution($tenant, $event, ['status' => 'refunded', 'paid_at' => '2026-10-10 12:00:00']);
    $source->forceFill(['updated_at' => '2026-10-11 12:00:00'])->save(['timestamps' => false]);
    app(GivingContributionBridge::class)->associate($tenant, true);
    $report = app(App\Services\Giving\GivingReport::class)->summary($tenant, Carbon\CarbonImmutable::parse('2026-10-01'), Carbon\CarbonImmutable::parse('2026-10-31'));
    expect($report['GHS']['collected_online'])->toBe(10000)->and($report['GHS']['refunds'])->toBe(0)->and($report['GHS']['undated_legacy_refunds'])->toBe(10000);
});

test('own account historic references and charges remain unchanged when associated', function (string $provider) {
    [$tenant] = GivingScenario::make();
    $tenant->update(['settlement_mode' => App\Models\Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'visibility' => Event::VISIBILITY_PRIVATE]);
    $source = givingLegacyContribution($tenant, $event, ['provider' => $provider]);
    app(App\Services\Finance\LedgerService::class)->recordOwnGatewayContribution($event, $source, 10000, 100, $source->payment_reference, 200, $provider, 100, 0, $source->payment_reference);
    $before = [$source->fresh()->getRawOriginal(), App\Models\LedgerTransaction::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('id')->get()->toArray(), App\Models\EventLedgerEntry::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('id')->get()->toArray()];
    app(GivingContributionBridge::class)->associate($tenant, true);
    app(GivingContributionBridge::class)->associate($tenant, true);
    expect([$source->fresh()->getRawOriginal(), App\Models\LedgerTransaction::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('id')->get()->toArray(), App\Models\EventLedgerEntry::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('id')->get()->toArray()])->toBe($before);
    expect(app(App\Services\Giving\GivingBalance::class)->payable($tenant, 'GHS'))->toBe(0);
})->with(['paystack', 'stripe']);

test('association preserves the existing verified payer receipt route and eligibility', function () {
    [$tenant] = GivingScenario::make();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $source = givingLegacyContribution($tenant, $event);
    $pdf = Mockery::mock(Barryvdh\DomPDF\PDF::class);
    $pdf->shouldReceive('setPaper')->andReturnSelf();
    $pdf->shouldReceive('output')->andReturn('%PDF legacy receipt');
    Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')->andReturn($pdf);
    $host = mb_ltrim((string) config('session.domain'), '.');
    $this->withSession([App\Services\Events\PlatformAttendeeVerification::SESSION_KEY => ['email' => 'giver@example.test', 'expires_at' => now()->addHour()->timestamp]]);
    $before = $this->get('http://'.$host.'/my/contributions/'.$source->id.'/receipt')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    app(GivingContributionBridge::class)->associate($tenant, true);
    $after = $this->get('http://'.$host.'/my/contributions/'.$source->id.'/receipt')->assertOk();
    expect($after->getContent())->toBe($before->getContent())->and($after->headers->get('Content-Disposition'))->toBe($before->headers->get('Content-Disposition'));
});
