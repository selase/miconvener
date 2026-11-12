<?php

declare(strict_types=1);

use App\Models\BadgeLibraryDesign;
use App\Models\BadgeTemplateLicence;
use App\Models\BadgeTemplatePurchase;
use App\Models\Event;
use App\Models\StoreOrder;
use App\Models\Tenant;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    refreshTenantDatabases();
    $this->event = Event::factory()->create();
    $this->family = BadgeLibraryDesign::query()->create(['scope' => 'platform', 'name' => 'Paid family', 'price_pesewas' => 5000]);
});

function badgeSchemaRejects(Closure $write): void
{
    expect(fn () => (new BadgeTemplateLicence)->getConnection()->transaction($write))->toThrow(QueryException::class);
}

test('a licence slot cannot be duplicated for the same organisation event and family', function (): void {
    $data = ['buyer_tenant_id' => $this->event->tenant_id, 'event_id' => $this->event->id, 'family_id' => $this->family->id];
    BadgeTemplateLicence::query()->create($data);
    badgeSchemaRejects(fn () => BadgeTemplateLicence::query()->create($data));
    expect(BadgeTemplateLicence::query()->count())->toBe(1);
});

test('a licence event must belong to the purchasing organisation', function (): void {
    badgeSchemaRejects(fn () => BadgeTemplateLicence::query()->create(['buyer_tenant_id' => Tenant::factory()->create()->id, 'event_id' => $this->event->id, 'family_id' => $this->family->id]));
});

test('licence history prevents permanent event deletion', function (): void {
    BadgeTemplateLicence::query()->create(['buyer_tenant_id' => $this->event->tenant_id, 'event_id' => $this->event->id, 'family_id' => $this->family->id]);
    badgeSchemaRejects(fn () => $this->event->getConnection()->table('events')->where('id', $this->event->id)->delete());
});

test('digital purchase terms and buyer identity are immutable', function (): void {
    $slot = BadgeTemplateLicence::query()->create(['buyer_tenant_id' => $this->event->tenant_id, 'event_id' => $this->event->id, 'family_id' => $this->family->id]);
    $version = $this->family->versions()->create(['version' => 1, 'snapshot' => []]);
    $purchase = BadgeTemplatePurchase::query()->create(['licence_id' => $slot->id, 'order_id' => StoreOrder::factory()->badgeTemplate()->create()->id, 'buyer_tenant_id' => $this->event->tenant_id, 'event_id' => $this->event->id, 'family_id' => $this->family->id, 'version_id' => $version->id, 'terms_snapshot' => ['total_pesewas' => 5000], 'state' => 'pending']);
    badgeSchemaRejects(fn () => $purchase->update(['terms_snapshot' => ['total_pesewas' => 1]]));
    badgeSchemaRejects(fn () => $purchase->update(['buyer_tenant_id' => Tenant::factory()->create()->id]));
});

test('one pending purchase per licence slot is enforced independently of checkout keys', function (): void {
    $licence = BadgeTemplateLicence::query()->create(['buyer_tenant_id' => $this->event->tenant_id, 'event_id' => $this->event->id, 'family_id' => $this->family->id]);
    $version = $this->family->versions()->create(['version' => 1, 'snapshot' => []]);
    $data = ['licence_id' => $licence->id, 'buyer_tenant_id' => $this->event->tenant_id, 'event_id' => $this->event->id, 'family_id' => $this->family->id, 'version_id' => $version->id, 'terms_snapshot' => ['total_pesewas' => 5000], 'state' => 'pending'];
    $first = BadgeTemplatePurchase::query()->create($data + ['order_id' => StoreOrder::factory()->badgeTemplate()->create()->id]);
    $secondOrder = StoreOrder::factory()->badgeTemplate()->create();
    badgeSchemaRejects(fn () => BadgeTemplatePurchase::query()->create($data + ['order_id' => $secondOrder->id]));
    $first->update(['state' => 'refunded']);
    BadgeTemplatePurchase::query()->create($data + ['order_id' => $secondOrder->id]);
    expect(BadgeTemplatePurchase::query()->count())->toBe(2);
});

test('a digital purchase must use the version of its licensed family', function (): void {
    $licence = BadgeTemplateLicence::query()->create(['buyer_tenant_id' => $this->event->tenant_id, 'event_id' => $this->event->id, 'family_id' => $this->family->id]);
    $other = BadgeLibraryDesign::query()->create(['scope' => 'platform', 'name' => 'Other', 'price_pesewas' => 5000]);
    $version = $other->versions()->create(['version' => 1, 'snapshot' => []]);
    $order = StoreOrder::factory()->badgeTemplate()->create();
    badgeSchemaRejects(fn () => BadgeTemplatePurchase::query()->create(['licence_id' => $licence->id, 'buyer_tenant_id' => $this->event->tenant_id, 'event_id' => $this->event->id, 'family_id' => $this->family->id, 'version_id' => $version->id, 'order_id' => $order->id, 'terms_snapshot' => [], 'state' => 'pending']));
});

test('a licence slot cannot be reassigned to a different event', function (): void {
    $slot = BadgeTemplateLicence::query()->create(['buyer_tenant_id' => $this->event->tenant_id, 'event_id' => $this->event->id, 'family_id' => $this->family->id]);
    $other = Event::factory()->create(['tenant_id' => $this->event->tenant_id]);
    badgeSchemaRejects(fn () => $slot->update(['event_id' => $other->id]));
});
