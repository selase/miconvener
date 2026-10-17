<?php

declare(strict_types=1);

use App\Models\GivingAdjustment;
use App\Models\GivingGift;
use App\Services\Giving\GivingReport;
use Carbon\CarbonImmutable;
use Tests\Support\GivingScenario;

test('giving report separates recorded cash online and dated corrections by currency', function () {
    [$tenant,, $fund] = GivingScenario::make();
    $gift = GivingGift::factory()->create(['tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'amount' => 10000, 'source' => 'recorded_cash', 'status' => 'completed', 'collected_at' => '2026-10-10 12:00:00']);
    GivingGift::factory()->create(['tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'amount' => 20000, 'source' => 'online', 'status' => 'completed', 'paid_at' => '2026-10-11 12:00:00']);
    GivingAdjustment::factory()->create(['tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'gift_id' => $gift->id, 'event_contribution_id' => null, 'kind' => 'cash_correction', 'principal' => 1000, 'status' => 'confirmed', 'confirmed_at' => '2026-10-12 12:00:00']);
    $report = app(GivingReport::class)->summary($tenant, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));
    expect($report['GHS']['recorded_cash'])->toBe(10000)->and($report['GHS']['collected_online'])->toBe(20000)->and($report['GHS']['corrections'])->toBe(1000)->and($report['GHS']['available'])->toBe(0);
});

test('giving reports require finance support filters and neutralise formula cells', function () {
    [$tenant,$user,$fund] = GivingScenario::make(['name' => '=CMD()']);
    GivingGift::factory()->create(['tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'status' => 'completed', 'source' => 'recorded_cash', 'collected_at' => now()]);
    $url = 'http://'.eventSubdomainHost($tenant->slug).'/community/money/giving/report';
    $this->actingAs($user)->get($url)->assertOk()->assertInertia(fn ($page) => $page->component('Tenant/Community/GivingReport'));
    $this->get($url.'?format=csv')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertStreamedContent("Currency,\"Collected online\",\"Recorded cash\",Refunds,Corrections,\"Net online\",Available,\"Undated legacy refunds (excluded from dated net)\"\nGHS,0,10000,0,0,0,0,0\n");
    $this->getJson($url.'?fund='.App\Models\GivingFund::factory()->create()->id)->assertUnprocessable();
    $this->getJson($url.'?from=2026-12-01&to=2026-01-01')->assertUnprocessable();
    $user->revokePermissionTo('read finance');
    $this->get($url)->assertForbidden();
});
