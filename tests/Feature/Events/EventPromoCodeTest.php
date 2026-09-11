<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventPromoCode;
use App\Models\EventRegistration;
use App\Models\EventTicketType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('tenant organizer can create, update, toggle and delete promo codes', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    // Create promo code
    $response = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/promo-codes", [
            'code' => 'save20',
            'discount_type' => 'percentage',
            'discount_value' => 20,
            'max_redemptions' => 50,
            'max_per_attendee' => 1,
            'is_active' => true,
        ], ['HTTP_HOST' => $host]);

    $response->assertCreated();
    $this->assertDatabaseHas('event_promo_codes', [
        'event_id' => $event->id,
        'code' => 'SAVE20',
        'discount_type' => 'percentage',
        'discount_value' => 20,
    ], 'landlord');

    $promo = EventPromoCode::where('code', 'SAVE20')->first();

    // Toggle promo code
    $toggleRes = $this->actingAs($user)
        ->patchJson("http://{$host}/events/{$event->id}/promo-codes/{$promo->id}/toggle", [], ['HTTP_HOST' => $host]);
    $toggleRes->assertOk();
    expect($promo->fresh()->is_active)->toBeFalse();

    // Update promo code
    $updateRes = $this->actingAs($user)
        ->putJson("http://{$host}/events/{$event->id}/promo-codes/{$promo->id}", [
            'code' => 'save25',
            'discount_type' => 'percentage',
            'discount_value' => 25,
            'max_per_attendee' => 1,
            'is_active' => true,
        ], ['HTTP_HOST' => $host]);
    $updateRes->assertOk();
    expect($promo->fresh()->code)->toBe('SAVE25')
        ->and($promo->fresh()->discount_value)->toBe(25);

    // Delete promo code
    $deleteRes = $this->actingAs($user)
        ->deleteJson("http://{$host}/events/{$event->id}/promo-codes/{$promo->id}", [], ['HTTP_HOST' => $host]);
    $deleteRes->assertOk();
    $this->assertDatabaseMissing('event_promo_codes', ['id' => $promo->id], 'landlord');
});

test('attendee can validate promo code via public endpoint', function () {
    [$tenant] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $ticketType = EventTicketType::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'name' => 'General Admission',
        'price' => 10000, // GHS 100.00
    ]);

    EventPromoCode::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'code' => 'DISCOUNT50',
        'discount_type' => EventPromoCode::TYPE_FIXED,
        'discount_value' => 3000, // GHS 30.00
        'is_active' => true,
    ]);

    $response = $this->postJson("http://{$host}/e/{$event->slug}/validate-promo", [
        'code' => 'discount50',
        'ticket_type_id' => $ticketType->id,
        'amount' => 10000,
    ], ['HTTP_HOST' => $host]);

    $response->assertOk()
        ->assertJson([
            'valid' => true,
            'code' => 'DISCOUNT50',
            'discount_type' => 'fixed_amount',
            'discount_value' => 3000,
            'discount_amount' => 3000,
            'final_amount' => 7000,
        ]);
});

test('percentage discount reduces registration amount and records redemption', function () {
    Mail::fake();

    [$tenant] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $ticketType = EventTicketType::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'name' => 'Standard Pass',
        'price' => 20000, // GHS 200.00
    ]);

    $promo = EventPromoCode::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'code' => 'VIP20',
        'discount_type' => EventPromoCode::TYPE_PERCENTAGE,
        'discount_value' => 20, // 20%
        'max_redemptions' => 10,
        'is_active' => true,
    ]);

    $this->post("http://{$host}/e/{$event->slug}/register", [
        'title' => 'Dr',
        'first_name' => 'Kwame',
        'last_name' => 'Mensah',
        'email' => 'kwame@example.com',
        'ticket_type_id' => $ticketType->id,
        'promo_code' => 'vip20',
    ], ['HTTP_HOST' => $host]);

    $registration = EventRegistration::where('email', 'kwame@example.com')->first();
    expect($registration)->not->toBeNull()
        ->and($registration->promo_code_id)->toBe($promo->id)
        ->and($registration->discount_amount)->toBe(4000) // 20% of 20000
        ->and($registration->status)->toBe(EventRegistration::STATUS_PENDING_PAYMENT);

    expect($promo->fresh()->redemptions_count)->toBe(1);
});

test('complimentary pass zeroes out ticket cost and confirms registration immediately', function () {
    Mail::fake();

    [$tenant] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $ticketType = EventTicketType::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'name' => 'Keynote Ticket',
        'price' => 15000, // GHS 150.00
    ]);

    $promo = EventPromoCode::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'code' => 'PRESSFREE',
        'discount_type' => EventPromoCode::TYPE_COMPLIMENTARY,
        'discount_value' => 0,
        'is_active' => true,
    ]);

    $this->post("http://{$host}/e/{$event->slug}/register", [
        'title' => 'Ms',
        'first_name' => 'Akosua',
        'last_name' => 'Addae',
        'email' => 'akosua@press.org',
        'ticket_type_id' => $ticketType->id,
        'promo_code' => 'pressfree',
    ], ['HTTP_HOST' => $host]);

    $registration = EventRegistration::where('email', 'akosua@press.org')->first();
    expect($registration)->not->toBeNull()
        ->and($registration->promo_code_id)->toBe($promo->id)
        ->and($registration->discount_amount)->toBe(15000)
        ->and($registration->status)->toBe(EventRegistration::STATUS_CONFIRMED);

    expect($promo->fresh()->redemptions_count)->toBe(1);
});

test('promo code usage limits and caps are strictly enforced', function () {
    Mail::fake();

    [$tenant] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $ticketType = EventTicketType::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'price' => 10000,
    ]);

    $promo = EventPromoCode::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'code' => 'LIMITED1',
        'discount_type' => EventPromoCode::TYPE_FIXED,
        'discount_value' => 5000,
        'max_redemptions' => 1,
        'is_active' => true,
    ]);

    // First user redeems
    $this->post("http://{$host}/e/{$event->slug}/register", [
        'title' => 'Mr',
        'first_name' => 'User',
        'last_name' => 'One',
        'email' => 'one@example.com',
        'ticket_type_id' => $ticketType->id,
        'promo_code' => 'LIMITED1',
    ], ['HTTP_HOST' => $host]);

    expect($promo->fresh()->redemptions_count)->toBe(1);

    // Second user tries to redeem maxed out promo code
    $res = $this->post("http://{$host}/e/{$event->slug}/register", [
        'title' => 'Mr',
        'first_name' => 'User',
        'last_name' => 'Two',
        'email' => 'two@example.com',
        'ticket_type_id' => $ticketType->id,
        'promo_code' => 'LIMITED1',
    ], ['HTTP_HOST' => $host]);

    $res->assertSessionHasErrors('promo_code');
});

test('invite-only ticket types require valid access code and can be unlocked', function () {
    Mail::fake();

    [$tenant] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $publicTicket = EventTicketType::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'name' => 'Regular Pass',
        'price' => 5000,
        'access_code' => null,
    ]);

    $vipTicket = EventTicketType::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'name' => 'VIP Backstage',
        'price' => 50000,
        'access_code' => 'SECRETACCESS',
    ]);

    // Unlocking tickets via public endpoint
    $unlockRes = $this->postJson("http://{$host}/e/{$event->slug}/unlock-tickets", [
        'access_code' => 'secretaccess',
    ], ['HTTP_HOST' => $host]);

    $unlockRes->assertOk()
        ->assertJson([
            'message' => 'Access code accepted.',
        ]);
    expect($unlockRes->json('ticket_types.0.id'))->toBe($vipTicket->id);

    // Attempting to register for VIP without access code fails
    $failReg = $this->post("http://{$host}/e/{$event->slug}/register", [
        'title' => 'Mr',
        'first_name' => 'Sneaky',
        'last_name' => 'Attendee',
        'email' => 'sneaky@example.com',
        'ticket_type_id' => $vipTicket->id,
    ], ['HTTP_HOST' => $host]);
    $failReg->assertSessionHasErrors('access_code');

    // Registering for VIP with correct access code succeeds
    $successReg = $this->post("http://{$host}/e/{$event->slug}/register", [
        'title' => 'Mr',
        'first_name' => 'Honored',
        'last_name' => 'Guest',
        'email' => 'vip@example.com',
        'ticket_type_id' => $vipTicket->id,
        'access_code' => 'secretaccess',
    ], ['HTTP_HOST' => $host]);

    $vipRegistration = EventRegistration::where('email', 'vip@example.com')->first();
    expect($vipRegistration)->not->toBeNull()
        ->and($vipRegistration->ticket_type_id)->toBe($vipTicket->id);
});
