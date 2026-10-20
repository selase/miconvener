<?php

declare(strict_types=1);

use App\Models\Member;
use App\Models\Package;
use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Models\VenueBooking;
use App\Services\Events\PlatformAttendeeVerification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

/**
 * Staff see and request their own organisation's rooms from /my, free of charge.
 * A verified person who is not staff learns nothing about the rooms, not even
 * that there are any.
 */
beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);
    Mail::fake();

    $this->tenant = Tenant::factory()->create(['package_id' => Package::query()->where('slug', 'enterprise')->value('id')]);
    $this->tenant->syncFeaturesFromPackage();
    $this->other = Tenant::factory()->create(['package_id' => Package::query()->where('slug', 'enterprise')->value('id')]);
    $this->other->syncFeaturesFromPackage();

    $room = fn (Tenant $tenant, string $title, string $audience): StoreListing => StoreListing::factory()->create([
        'shop_id' => Shop::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true])->id, 'title' => $title,
        'listing_kind' => StoreListing::KIND_VENUE, 'status' => StoreListing::STATUS_PUBLISHED, 'audience' => $audience,
    ]);
    $this->staffRoom = $room($this->tenant, 'Council Chamber', StoreListing::AUDIENCE_STAFF);
    $this->openRoom = StoreListing::factory()->create(['shop_id' => $this->staffRoom->shop_id, 'title' => 'Open Hall', 'listing_kind' => StoreListing::KIND_VENUE, 'status' => StoreListing::STATUS_PUBLISHED, 'audience' => StoreListing::AUDIENCE_PUBLIC]);
    $this->otherRoom = $room($this->other, 'Other Boardroom', StoreListing::AUDIENCE_STAFF);

    $this->staff = Member::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'ama@ugmc.example', 'is_staff' => true]);
    $this->colleague = Member::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'kofi@ugmc.example', 'is_staff' => true]);
    $this->plain = Member::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'yaw@example.test', 'is_staff' => false]);

    $this->base = 'http://'.mb_ltrim((string) config('session.domain'), '.').'/my/communities/'.$this->tenant->slug;
    $this->as = fn (string $email): array => [PlatformAttendeeVerification::SESSION_KEY => ['email' => $email, 'verified_at' => now()->timestamp, 'expires_at' => now()->addHour()->timestamp]];
    $this->nine = CarbonImmutable::now()->addDays(3)->setTime(9, 0);
    $this->body = fn (array $overrides = []): array => $overrides + ['starts_at' => $this->nine->toIso8601String(), 'ends_at' => $this->nine->addHours(2)->toIso8601String(), 'purpose' => 'Budget review', 'department' => 'Finance'];
});

test('staff see the staff-only and public rooms of their organisation, with busy times', function (): void {
    VenueBooking::factory()->create(['tenant_id' => $this->tenant->id, 'shop_id' => $this->staffRoom->shop_id, 'store_listing_id' => $this->staffRoom->id, 'starts_at' => $this->nine, 'ends_at' => $this->nine->addHours(2), 'planner_name' => 'Secret Person']);

    $response = $this->withSession(($this->as)('ama@ugmc.example'))->getJson($this->base.'/rooms?from='.$this->nine->subDay()->toDateString())->assertOk();

    expect(collect($response->json('rooms'))->pluck('title')->sort()->values()->all())->toBe(['Council Chamber', 'Open Hall'])
        ->and(collect($response->json('rooms'))->firstWhere('title', 'Council Chamber')['busy'])->toHaveCount(1)
        ->and($response->getContent())->not->toContain('Secret Person')->not->toContain('Other Boardroom');
});

test('a verified person who is not staff gets nothing, as if the rooms did not exist', function (): void {
    $this->withSession(($this->as)('yaw@example.test'))->getJson($this->base.'/rooms')->assertNotFound();
    $this->withSession(($this->as)('stranger@example.test'))->getJson($this->base.'/rooms')->assertNotFound();
    $this->withSession(($this->as)('yaw@example.test'))->postJson($this->base.'/rooms/'.$this->staffRoom->id.'/requests', ($this->body)())->assertNotFound();
});

test('staff of one organisation cannot see the rooms of another', function (): void {
    $this->withSession(($this->as)('ama@ugmc.example'))->getJson('http://'.mb_ltrim((string) config('session.domain'), '.').'/my/communities/'.$this->other->slug.'/rooms')->assertNotFound();
});

test('staff request a room, see it as theirs, and cancel it', function (): void {
    $created = $this->withSession(($this->as)('ama@ugmc.example'))->postJson($this->base.'/rooms/'.$this->staffRoom->id.'/requests', ($this->body)())->assertCreated();

    expect($created->json('booking.status'))->toBe('pending_approval');

    $mine = $this->withSession(($this->as)('ama@ugmc.example'))->getJson($this->base.'/rooms')->json('mine');
    expect($mine)->toHaveCount(1)->and($mine[0]['room'])->toBe('Council Chamber');

    $this->withSession(($this->as)('ama@ugmc.example'))->deleteJson($this->base.'/room-requests/'.$created->json('booking.id'))->assertOk();
    expect(VenueBooking::query()->find($created->json('booking.id'))->status)->toBe(VenueBooking::STATUS_CANCELLED);
});

test('the booking rules are enforced and explained', function (): void {
    $this->withSession(($this->as)('ama@ugmc.example'))
        ->postJson($this->base.'/rooms/'.$this->staffRoom->id.'/requests', ($this->body)(['ends_at' => $this->nine->addHours(9)->toIso8601String()]))
        ->assertUnprocessable()->assertJsonValidationErrors('ends_at');
});

test('a person cannot cancel someone else booking, and another organisation room cannot be requested', function (): void {
    $theirs = VenueBooking::factory()->internal()->create(['tenant_id' => $this->tenant->id, 'shop_id' => $this->staffRoom->shop_id, 'store_listing_id' => $this->staffRoom->id, 'requested_for_member_id' => $this->colleague->id]);

    $this->withSession(($this->as)('ama@ugmc.example'))->deleteJson($this->base.'/room-requests/'.$theirs->id)->assertUnprocessable();
    expect($theirs->fresh()->status)->toBe(VenueBooking::STATUS_PENDING_APPROVAL);

    $this->withSession(($this->as)('ama@ugmc.example'))->postJson($this->base.'/rooms/'.$this->otherRoom->id.'/requests', ($this->body)())->assertNotFound();
});

test('a verified-domain address is staff and is added as a member on its first request', function (): void {
    $this->tenant->update(['staff_email_domains' => [['domain' => 'ugmc.example', 'token' => 'x', 'verified_at' => now()->toIso8601String()]]]);

    $this->withSession(($this->as)('efua@ugmc.example'))->getJson($this->base.'/rooms')->assertOk();
    expect(Member::query()->where('email', 'efua@ugmc.example')->exists())->toBeFalse();

    $this->withSession(($this->as)('efua@ugmc.example'))->postJson($this->base.'/rooms/'.$this->staffRoom->id.'/requests', ($this->body)())->assertCreated();
    expect(Member::query()->where('email', 'efua@ugmc.example')->value('is_staff'))->toBeTrue();
});

test('the organisation card tells the app when the person can book rooms', function (): void {
    $this->withSession(($this->as)('ama@ugmc.example'))->getJson('http://'.mb_ltrim((string) config('session.domain'), '.').'/my/communities')->assertOk()
        ->assertJsonPath('communities.data.0.actions.rooms', true);
    $this->withSession(($this->as)('yaw@example.test'))->getJson('http://'.mb_ltrim((string) config('session.domain'), '.').'/my/communities')->assertOk()
        ->assertJsonPath('communities.data.0.actions.rooms', false);
});
