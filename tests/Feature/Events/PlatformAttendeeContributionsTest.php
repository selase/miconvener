<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Enum\TenantStatusEnum;
use App\Models\Event;
use App\Models\EventContribution;
use App\Models\Tenant;
use App\Services\Events\PlatformAttendeeVerification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Mail::fake();
    Queue::fake();
});

if (! function_exists('Tests\Feature\Events\attendeePlatformHost')) {
    function attendeePlatformHost(): string
    {
        return mb_ltrim((string) config('session.domain'), '.');
    }
}

test('verified attendee can fetch contributions across multiple tenants', function (): void {
    $host = attendeePlatformHost();
    $email = 'ama@example.com';

    $tenantA = Tenant::factory()->create([
        'name' => 'First Baptist Church',
        'slug' => 'fbc',
        'isolation_mode' => 'shared',
    ]);

    $tenantB = Tenant::factory()->create([
        'name' => 'Ghana Memorial Trust',
        'slug' => 'gmt',
        'isolation_mode' => 'shared',
    ]);

    $eventA = Event::factory()->published()->create([
        'tenant_id' => $tenantA->id,
        'name' => 'Annual Harvest Thanksgiving',
        'slug' => 'harvest-2026',
        'event_category' => 'faith',
        'contribution_title' => 'Tithe & Offering',
    ]);

    $eventB = Event::factory()->published()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'Celebration of Life — Dr. Mensah',
        'slug' => 'mensah-memorial',
        'event_category' => 'memorial',
        'contribution_title' => 'Memorial Tribute',
    ]);

    $contribA = EventContribution::create([
        'tenant_id' => $tenantA->id,
        'event_id' => $eventA->id,
        'contributor_name' => 'Ama Osei',
        'contributor_email' => $email,
        'amount' => 5000, // 50.00 GHS in pesewas
        'gateway_fee_amount' => 100,
        'platform_fee_amount' => 150,
        'net_amount' => 4750,
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_COMPLETED,
        'payment_reference' => 'CONTRIB-FBC-001',
        'paystack_reference' => 'pstk_fbc_001',
        'provider' => 'paystack',
        'tribute_message' => 'Blessings to the ministry',
        'is_anonymous' => false,
        'is_approved' => true,
        'paid_at' => now()->subDay(),
    ]);

    $contribB = EventContribution::create([
        'tenant_id' => $tenantB->id,
        'event_id' => $eventB->id,
        'contributor_name' => 'Ama Osei',
        'contributor_email' => $email,
        'amount' => 15000, // 150.00 GHS in pesewas
        'gateway_fee_amount' => 300,
        'platform_fee_amount' => 450,
        'net_amount' => 14250,
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_COMPLETED,
        'payment_reference' => 'CONTRIB-GMT-002',
        'paystack_reference' => 'pstk_gmt_002',
        'provider' => 'paystack',
        'tribute_message' => 'Rest in peace doctor',
        'is_anonymous' => true,
        'is_approved' => true,
        'paid_at' => now(),
    ]);

    $response = $this->withSession([
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => $email,
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(12)->getTimestamp(),
        ],
    ])->get("http://{$host}/my/contributions", ['HTTP_HOST' => $host]);

    $response->assertOk()
        ->assertJsonCount(2)
        ->assertJsonFragment([
            'id' => $contribA->id,
            'payment_reference' => 'CONTRIB-FBC-001',
            'amount' => 5000,
            'formatted_amount' => 'GHS 50.00',
            'tribute_message' => 'Blessings to the ministry',
            'is_anonymous' => false,
        ])
        ->assertJsonFragment([
            'id' => $contribB->id,
            'payment_reference' => 'CONTRIB-GMT-002',
            'amount' => 15000,
            'formatted_amount' => 'GHS 150.00',
            'tribute_message' => 'Rest in peace doctor',
            'is_anonymous' => true,
        ]);
});

test('contributions list respects organiser filter scoping', function (): void {
    $host = attendeePlatformHost();
    $email = 'ama@example.com';

    $tenantA = Tenant::factory()->create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'isolation_mode' => 'shared']);
    $tenantB = Tenant::factory()->create(['name' => 'Tenant B', 'slug' => 'tenant-b', 'isolation_mode' => 'shared']);

    $eventA = Event::factory()->published()->create(['tenant_id' => $tenantA->id]);
    $eventB = Event::factory()->published()->create(['tenant_id' => $tenantB->id]);

    EventContribution::create([
        'tenant_id' => $tenantA->id,
        'event_id' => $eventA->id,
        'contributor_name' => 'Ama Osei',
        'contributor_email' => $email,
        'amount' => 5000,
        'gateway_fee_amount' => 0,
        'platform_fee_amount' => 0,
        'net_amount' => 5000,
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_COMPLETED,
        'payment_reference' => 'REF-A',
        'provider' => 'paystack',
        'paid_at' => now(),
    ]);

    EventContribution::create([
        'tenant_id' => $tenantB->id,
        'event_id' => $eventB->id,
        'contributor_name' => 'Ama Osei',
        'contributor_email' => $email,
        'amount' => 7000,
        'gateway_fee_amount' => 0,
        'platform_fee_amount' => 0,
        'net_amount' => 7000,
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_COMPLETED,
        'payment_reference' => 'REF-B',
        'provider' => 'paystack',
        'paid_at' => now(),
    ]);

    $response = $this->withSession([
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => $email,
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(12)->getTimestamp(),
        ],
    ])->get("http://{$host}/my/contributions?organiser=tenant-a", ['HTTP_HOST' => $host]);

    $response->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['payment_reference' => 'REF-A'])
        ->assertJsonMissing(['payment_reference' => 'REF-B']);
});

test('cross-attendee isolation prevents seeing another attendee giving records', function (): void {
    $host = attendeePlatformHost();
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    EventContribution::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'contributor_name' => 'Kofi Mensah',
        'contributor_email' => 'kofi@example.com',
        'amount' => 20000,
        'gateway_fee_amount' => 0,
        'platform_fee_amount' => 0,
        'net_amount' => 20000,
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_COMPLETED,
        'payment_reference' => 'REF-KOFI',
        'provider' => 'paystack',
        'paid_at' => now(),
    ]);

    $response = $this->withSession([
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => 'ama@example.com',
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(12)->getTimestamp(),
        ],
    ])->get("http://{$host}/my/contributions", ['HTTP_HOST' => $host]);

    $response->assertOk()->assertJsonCount(0);
});

test('unauthenticated access to contributions is rejected with 401', function (): void {
    $host = attendeePlatformHost();

    $response = $this->get("http://{$host}/my/contributions", [
        'HTTP_HOST' => $host,
        'HTTP_ACCEPT' => 'application/json',
    ]);

    $response->assertStatus(401);
});

test('incomplete contributions are excluded from giving history', function (): void {
    $host = attendeePlatformHost();
    $email = 'ama@example.com';
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    EventContribution::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'contributor_name' => 'Ama Osei',
        'contributor_email' => $email,
        'amount' => 5000,
        'gateway_fee_amount' => 0,
        'platform_fee_amount' => 0,
        'net_amount' => 5000,
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_PENDING_PAYMENT,
        'payment_reference' => 'REF-PENDING',
        'provider' => 'paystack',
    ]);

    EventContribution::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'contributor_name' => 'Ama Osei',
        'contributor_email' => $email,
        'amount' => 6000,
        'gateway_fee_amount' => 0,
        'platform_fee_amount' => 0,
        'net_amount' => 6000,
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_FAILED,
        'payment_reference' => 'REF-FAILED',
        'provider' => 'paystack',
    ]);

    $response = $this->withSession([
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => $email,
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(12)->getTimestamp(),
        ],
    ])->get("http://{$host}/my/contributions", ['HTTP_HOST' => $host]);

    $response->assertOk()->assertJsonCount(0);
});

test('contributions from banned tenants are excluded', function (): void {
    $host = attendeePlatformHost();
    $email = 'ama@example.com';
    $tenant = Tenant::factory()->create([
        'isolation_mode' => 'shared',
        'status' => TenantStatusEnum::BANNED,
    ]);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    EventContribution::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'contributor_name' => 'Ama Osei',
        'contributor_email' => $email,
        'amount' => 5000,
        'gateway_fee_amount' => 0,
        'platform_fee_amount' => 0,
        'net_amount' => 5000,
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_COMPLETED,
        'payment_reference' => 'REF-BANNED',
        'provider' => 'paystack',
        'paid_at' => now(),
    ]);

    $response = $this->withSession([
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => $email,
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(12)->getTimestamp(),
        ],
    ])->get("http://{$host}/my/contributions", ['HTTP_HOST' => $host]);

    $response->assertOk()->assertJsonCount(0);
});

test('verified attendee can download PDF donation receipt', function (): void {
    $host = attendeePlatformHost();
    $email = 'ama@example.com';
    $tenant = Tenant::factory()->create(['name' => 'Hope Foundation', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Clean Water Fundraiser',
        'contribution_title' => 'Charitable Donation',
    ]);

    $contribution = EventContribution::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'contributor_name' => 'Ama Osei',
        'contributor_email' => $email,
        'amount' => 25000,
        'gateway_fee_amount' => 500,
        'platform_fee_amount' => 750,
        'net_amount' => 23750,
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_COMPLETED,
        'payment_reference' => 'DONATION-987654',
        'paystack_reference' => 'pstk_987654',
        'provider' => 'paystack',
        'tribute_message' => 'For clean water in the villages',
        'is_anonymous' => false,
        'is_approved' => true,
        'paid_at' => now(),
    ]);

    $response = $this->withSession([
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => $email,
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(12)->getTimestamp(),
        ],
    ])->get("http://{$host}/my/contributions/{$contribution->id}/receipt", ['HTTP_HOST' => $host]);

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'attachment; filename="receipt-donation-987654.pdf"');

    expect($response->getContent())->toBeString()
        ->and(str_starts_with($response->getContent(), '%PDF-'))->toBeTrue();
});

test('cross-attendee receipt download is rejected with 403', function (): void {
    $host = attendeePlatformHost();
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $kofiContribution = EventContribution::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'contributor_name' => 'Kofi Mensah',
        'contributor_email' => 'kofi@example.com',
        'amount' => 10000,
        'gateway_fee_amount' => 0,
        'platform_fee_amount' => 0,
        'net_amount' => 10000,
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_COMPLETED,
        'payment_reference' => 'DON-KOFI-1',
        'provider' => 'paystack',
        'paid_at' => now(),
    ]);

    // Ama tries to download Kofi's receipt
    $response = $this->withSession([
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => 'ama@example.com',
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(12)->getTimestamp(),
        ],
    ])->get("http://{$host}/my/contributions/{$kofiContribution->id}/receipt", [
        'HTTP_HOST' => $host,
        'HTTP_ACCEPT' => 'application/json',
    ]);

    $response->assertStatus(403);
});

test('receipt download for uncompleted contribution is rejected with 422', function (): void {
    $host = attendeePlatformHost();
    $email = 'ama@example.com';
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $pending = EventContribution::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'contributor_name' => 'Ama Osei',
        'contributor_email' => $email,
        'amount' => 5000,
        'gateway_fee_amount' => 0,
        'platform_fee_amount' => 0,
        'net_amount' => 5000,
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_PENDING_PAYMENT,
        'payment_reference' => 'DON-PENDING',
        'provider' => 'paystack',
    ]);

    $response = $this->withSession([
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => $email,
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(12)->getTimestamp(),
        ],
    ])->get("http://{$host}/my/contributions/{$pending->id}/receipt", [
        'HTTP_HOST' => $host,
        'HTTP_ACCEPT' => 'application/json',
    ]);

    $response->assertStatus(422);
});

test('receipt download for non-existent contribution returns 404', function (): void {
    $host = attendeePlatformHost();

    $response = $this->withSession([
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => 'ama@example.com',
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(12)->getTimestamp(),
        ],
    ])->get("http://{$host}/my/contributions/00000000-0000-0000-0000-000000000000/receipt", [
        'HTTP_HOST' => $host,
        'HTTP_ACCEPT' => 'application/json',
    ]);

    $response->assertStatus(404);
});

test('verified attendee can update tribute message and anonymity preference', function (): void {
    $host = attendeePlatformHost();
    $email = 'ama@example.com';
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $contribution = EventContribution::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'contributor_name' => 'Ama Osei',
        'contributor_email' => $email,
        'amount' => 5000,
        'gateway_fee_amount' => 0,
        'platform_fee_amount' => 0,
        'net_amount' => 5000,
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_COMPLETED,
        'payment_reference' => 'DON-UPDATE-1',
        'provider' => 'paystack',
        'tribute_message' => 'Initial note',
        'is_anonymous' => false,
        'is_approved' => true,
        'paid_at' => now(),
    ]);

    $response = $this->withSession([
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => $email,
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(12)->getTimestamp(),
        ],
    ])->patch("http://{$host}/my/contributions/{$contribution->id}/tribute", [
        'tribute_message' => 'Updated: Rest peacefully with the angels.',
        'is_anonymous' => true,
    ], ['HTTP_HOST' => $host]);

    $response->assertOk()
        ->assertJson([
            'message' => 'Tribute message updated successfully.',
            'contribution' => [
                'id' => $contribution->id,
                'tribute_message' => 'Updated: Rest peacefully with the angels.',
                'is_anonymous' => true,
            ],
        ]);

    $contribution->refresh();
    expect($contribution->tribute_message)->toBe('Updated: Rest peacefully with the angels.')
        ->and($contribution->is_anonymous)->toBeTrue();
});

test('cross-attendee tribute editing is rejected with 403', function (): void {
    $host = attendeePlatformHost();
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $kofiContribution = EventContribution::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'contributor_name' => 'Kofi Mensah',
        'contributor_email' => 'kofi@example.com',
        'amount' => 5000,
        'gateway_fee_amount' => 0,
        'platform_fee_amount' => 0,
        'net_amount' => 5000,
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_COMPLETED,
        'payment_reference' => 'DON-KOFI-TRIB',
        'provider' => 'paystack',
        'paid_at' => now(),
    ]);

    $response = $this->withSession([
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => 'ama@example.com',
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(12)->getTimestamp(),
        ],
    ])->patch("http://{$host}/my/contributions/{$kofiContribution->id}/tribute", [
        'tribute_message' => 'Hacked message',
    ], [
        'HTTP_HOST' => $host,
        'HTTP_ACCEPT' => 'application/json',
    ]);

    $response->assertStatus(403);
});

test('tribute message length is validated up to 1000 characters', function (): void {
    $host = attendeePlatformHost();
    $email = 'ama@example.com';
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $contribution = EventContribution::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'contributor_name' => 'Ama Osei',
        'contributor_email' => $email,
        'amount' => 5000,
        'gateway_fee_amount' => 0,
        'platform_fee_amount' => 0,
        'net_amount' => 5000,
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_COMPLETED,
        'payment_reference' => 'DON-LEN-1',
        'provider' => 'paystack',
        'paid_at' => now(),
    ]);

    $response = $this->withSession([
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => $email,
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(12)->getTimestamp(),
        ],
    ])->patch("http://{$host}/my/contributions/{$contribution->id}/tribute", [
        'tribute_message' => str_repeat('a', 1001),
    ], [
        'HTTP_HOST' => $host,
        'HTTP_ACCEPT' => 'application/json',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['tribute_message']);
});

test('attendee portal page renders initialContributions for verified attendee', function (): void {
    $host = attendeePlatformHost();
    $email = 'ama@example.com';
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    EventContribution::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'contributor_name' => 'Ama Osei',
        'contributor_email' => $email,
        'amount' => 8000,
        'gateway_fee_amount' => 0,
        'platform_fee_amount' => 0,
        'net_amount' => 8000,
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_COMPLETED,
        'payment_reference' => 'DON-INITIAL-1',
        'provider' => 'paystack',
        'paid_at' => now(),
    ]);

    $this->withSession([
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => $email,
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(12)->getTimestamp(),
        ],
    ])->get("http://{$host}/my", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Events/AttendeePortal/MyPortal')
            ->has('initialContributions', 1)
            ->where('initialContributions.0.payment_reference', 'DON-INITIAL-1')
            ->where('initialContributions.0.amount', 8000)
        );
});

test('attendee can clear their existing tribute message', function (): void {
    $host = attendeePlatformHost();
    $email = 'ama@example.com';
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $contribution = EventContribution::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'contributor_name' => 'Ama Osei',
        'contributor_email' => $email,
        'amount' => 5000,
        'gateway_fee_amount' => 0,
        'platform_fee_amount' => 0,
        'net_amount' => 5000,
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_COMPLETED,
        'payment_reference' => 'DON-CLEAR-1',
        'provider' => 'paystack',
        'tribute_message' => 'Rest in Peace Grandma',
        'is_anonymous' => false,
        'is_approved' => true,
        'paid_at' => now(),
    ]);

    $response = $this->withSession([
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => $email,
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(12)->getTimestamp(),
        ],
    ])->patch("http://{$host}/my/contributions/{$contribution->id}/tribute", [
        'tribute_message' => '',
        'is_anonymous' => true,
    ], [
        'HTTP_HOST' => $host,
        'HTTP_ACCEPT' => 'application/json',
    ]);

    $response->assertOk()
        ->assertJson([
            'contribution' => [
                'id' => $contribution->id,
                'tribute_message' => null,
                'is_anonymous' => true,
            ],
        ]);

    $contribution->refresh();
    expect($contribution->tribute_message)->toBeNull();
    expect($contribution->is_anonymous)->toBeTrue();
});

test('updating tribute message resets is_approved to false for moderation', function (): void {
    $host = attendeePlatformHost();
    $email = 'ama@example.com';
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $contribution = EventContribution::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'contributor_name' => 'Ama Osei',
        'contributor_email' => $email,
        'amount' => 5000,
        'gateway_fee_amount' => 0,
        'platform_fee_amount' => 0,
        'net_amount' => 5000,
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_COMPLETED,
        'payment_reference' => 'DON-MOD-1',
        'provider' => 'paystack',
        'tribute_message' => 'Original Approved Message',
        'is_approved' => true,
        'paid_at' => now(),
    ]);

    $response = $this->withSession([
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => $email,
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(12)->getTimestamp(),
        ],
    ])->patch("http://{$host}/my/contributions/{$contribution->id}/tribute", [
        'tribute_message' => 'Edited New Message',
    ], [
        'HTTP_HOST' => $host,
        'HTTP_ACCEPT' => 'application/json',
    ]);

    $response->assertOk()
        ->assertJson([
            'contribution' => [
                'tribute_message' => 'Edited New Message',
                'is_approved' => false,
            ],
        ]);

    $contribution->refresh();
    expect($contribution->tribute_message)->toBe('Edited New Message');
    expect($contribution->is_approved)->toBeFalse();
});

test('uncompleted contribution cannot have its tribute updated', function (): void {
    $host = attendeePlatformHost();
    $email = 'ama@example.com';
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $contribution = EventContribution::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'contributor_name' => 'Ama Osei',
        'contributor_email' => $email,
        'amount' => 5000,
        'gateway_fee_amount' => 0,
        'platform_fee_amount' => 0,
        'net_amount' => 5000,
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_PENDING_PAYMENT,
        'payment_reference' => 'DON-PENDING-TRIB',
        'provider' => 'paystack',
    ]);

    $response = $this->withSession([
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => $email,
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(12)->getTimestamp(),
        ],
    ])->patch("http://{$host}/my/contributions/{$contribution->id}/tribute", [
        'tribute_message' => 'Should Not Update',
    ], [
        'HTTP_HOST' => $host,
        'HTTP_ACCEPT' => 'application/json',
    ]);

    $response->assertStatus(422);
});

test('banned tenant contribution cannot be accessed via receipt or tribute endpoints', function (): void {
    $host = attendeePlatformHost();
    $email = 'ama@example.com';
    $tenant = Tenant::factory()->create([
        'isolation_mode' => 'shared',
        'status' => TenantStatusEnum::BANNED,
    ]);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $contribution = EventContribution::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'contributor_name' => 'Ama Osei',
        'contributor_email' => $email,
        'amount' => 5000,
        'gateway_fee_amount' => 0,
        'platform_fee_amount' => 0,
        'net_amount' => 5000,
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_COMPLETED,
        'payment_reference' => 'DON-BANNED-1',
        'provider' => 'paystack',
        'paid_at' => now(),
    ]);

    $session = [
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => $email,
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(12)->getTimestamp(),
        ],
    ];

    $this->withSession($session)
        ->get("http://{$host}/my/contributions/{$contribution->id}/receipt", ['HTTP_HOST' => $host])
        ->assertStatus(404);

    $this->withSession($session)
        ->patch("http://{$host}/my/contributions/{$contribution->id}/tribute", ['tribute_message' => 'Hi'], [
            'HTTP_HOST' => $host,
            'HTTP_ACCEPT' => 'application/json',
        ])
        ->assertStatus(404);
});

test('unauthenticated requests to receipt and tribute endpoints are rejected with 401', function (): void {
    $host = attendeePlatformHost();
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $contribution = EventContribution::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'contributor_name' => 'Ama Osei',
        'contributor_email' => 'ama@example.com',
        'amount' => 5000,
        'gateway_fee_amount' => 0,
        'platform_fee_amount' => 0,
        'net_amount' => 5000,
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_COMPLETED,
        'payment_reference' => 'DON-UNAUTH-1',
        'provider' => 'paystack',
        'paid_at' => now(),
    ]);

    $this->get("http://{$host}/my/contributions/{$contribution->id}/receipt", [
        'HTTP_HOST' => $host,
        'HTTP_ACCEPT' => 'application/json',
    ])->assertStatus(401);

    $this->patch("http://{$host}/my/contributions/{$contribution->id}/tribute", [
        'tribute_message' => 'Unauthorized edit',
    ], [
        'HTTP_HOST' => $host,
        'HTTP_ACCEPT' => 'application/json',
    ])->assertStatus(401);
});
