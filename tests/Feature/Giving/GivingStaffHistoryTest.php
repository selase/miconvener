<?php

declare(strict_types=1);

use App\Models\GivingAdjustment;
use App\Models\GivingGift;
use Tests\Support\GivingScenario;

test('staff can page through older typed contributions and recover unresolved refunds beyond the recent list', function () {
    [$tenant, $user, $fund] = GivingScenario::make();
    $old = GivingGift::factory()->create(['tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'payer_name' => 'Older Giver', 'created_at' => now()->subMonth()]);
    GivingGift::factory()->count(51)->create(['tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'created_at' => now()]);
    $pending = GivingAdjustment::factory()->create(['tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'gift_id' => $old->id, 'kind' => 'refund', 'status' => 'unknown', 'created_at' => now()->subMonth()]);
    GivingAdjustment::factory()->count(51)->create(['tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'gift_id' => $old->id, 'status' => 'confirmed', 'created_at' => now()]);
    $host = 'http://'.eventSubdomainHost($tenant->slug);
    $this->actingAs($user)->get($host.'/community/money/giving?gifts_page=3&adjustments_page=3')->assertOk()->assertInertia(fn ($page) => $page->where('giftPages.current_page', 3)->where('giftPages.total', 52)->has('gifts', 2)->where('gifts.1.id', $old->id)->where('adjustmentPages.total', 52)->where('adjustments.1.id', $pending->id));
    $this->get($host.'/community/money/giving?q='.$old->id.'&refund_status=unresolved')->assertOk()->assertInertia(fn ($page) => $page->has('gifts', 1)->where('gifts.0.id', $old->id)->where('gifts.0.payer_name', 'Older Giver')->has('adjustments', 1)->where('adjustments.0.id', $pending->id));
    $this->getJson($host.'/community/money/giving?gifts_page=0')->assertUnprocessable();
});

test('staff history includes canonical event-origin Giving without duplicating a gift or exposing another tenant', function () {
    [$tenant, $user, $fund] = GivingScenario::make();
    $event = App\Models\Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $source = App\Models\EventContribution::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => 'completed', 'contributor_name' => 'Event Giver', 'payment_reference' => 'event-staff-history', 'amount' => 10000, 'currency' => 'GHS', 'paid_at' => now()]);
    App\Models\GivingContributionLink::factory()->create(['tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'event_contribution_id' => $source->id, 'origin' => 'checkout', 'category' => 'giving']);
    GivingGift::factory()->create(['payer_name' => 'OTHER TENANT PRIVATE']);
    $this->actingAs($user)->get('http://'.eventSubdomainHost($tenant->slug).'/community/money/giving')->assertOk()->assertDontSee('OTHER TENANT PRIVATE')->assertInertia(fn ($page) => $page->has('gifts', 1)->where('gifts.0.id', $source->id)->where('gifts.0.source_type', 'event_contribution')->where('gifts.0.can_refund', true));
    expect(GivingGift::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
});
