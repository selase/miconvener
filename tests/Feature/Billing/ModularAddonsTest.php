<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Contracts\PaymentGateway;
use App\Models\Event;
use App\Models\EventPoll;
use App\Models\Package;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Billing\TenantAddonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

final class ModularAddonsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\EventPackageSeeder']);
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\RoleSeeder']);
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\PermissionsSeeder']);
    }

    public function test_calibrated_packages_are_seeded_with_correct_prices_and_quotas(): void
    {
        $starter = Package::where('slug', 'starter')->firstOrFail();
        expect((float) $starter->price)->toEqual(249.0);
        expect((float) $starter->yearly_price)->toEqual(2490.0);
        expect($starter->features()->where('slug', 'live_polling')->first()->pivot->value)->toBeFalsy();
        expect($starter->features()->where('slug', 'team_seats')->first()->pivot->value)->toEqual(3);
        expect($starter->features()->where('slug', 'sms_credits')->first()->pivot->value)->toEqual(150);
        expect($starter->features()->where('slug', 'email_credits')->first()->pivot->value)->toEqual(3000);

        $growth = Package::where('slug', 'growth')->firstOrFail();
        expect((float) $growth->price)->toEqual(499.0);
        expect((float) $growth->yearly_price)->toEqual(4990.0);
        expect($growth->features()->where('slug', 'live_polling')->first()->pivot->value)->toBeTruthy();
        expect($growth->features()->where('slug', 'team_seats')->first()->pivot->value)->toEqual(6);
        expect($growth->features()->where('slug', 'sms_credits')->first()->pivot->value)->toEqual(500);
        expect($growth->features()->where('slug', 'email_credits')->first()->pivot->value)->toEqual(15000);
    }

    public function test_starter_tenant_cannot_create_polls_without_live_polling_addon(): void
    {
        [$tenant, $user, $host] = $this->createTenantOnPackage('starter', 'starter-poll-test');
        $event = Event::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)
            ->postJson("http://{$host}/events/{$event->id}/polls", [
                'question' => 'What is your favorite topic?',
                'type' => EventPoll::TYPE_MULTIPLE_CHOICE,
                'options' => ['Option A', 'Option B'],
            ], ['HTTP_HOST' => $host]);

        $response->assertStatus(403);
        $response->assertJsonFragment(['upgrade_required' => true]);
        expect(EventPoll::count())->toBe(0);
    }

    public function test_growth_tenant_can_create_polls_natively(): void
    {
        [$tenant, $user, $host] = $this->createTenantOnPackage('growth', 'growth-poll-test');
        $event = Event::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)
            ->postJson("http://{$host}/events/{$event->id}/polls", [
                'question' => 'How satisfied are you?',
                'type' => EventPoll::TYPE_MULTIPLE_CHOICE,
                'options' => ['Very Satisfied', 'Neutral'],
            ], ['HTTP_HOST' => $host]);

        $response->assertStatus(200);
        expect(EventPoll::count())->toBe(1);
    }

    public function test_single_event_pass_unlocks_live_polling_only_on_target_event(): void
    {
        [$tenant, $user, $host] = $this->createTenantOnPackage('starter', 'starter-pass-test');
        $eventA = Event::factory()->create(['tenant_id' => $tenant->id]);
        $eventB = Event::factory()->create(['tenant_id' => $tenant->id]);

        // Purchase single event pass for Event A
        TenantAddon::factory()->livePollingEventPass($eventA->id)->create([
            'tenant_id' => $tenant->id,
        ]);

        expect($tenant->canUseLivePolling($eventA))->toBeTrue();
        expect($tenant->canUseLivePolling($eventB))->toBeFalse();

        // Event A allows poll creation
        $resA = $this->actingAs($user)
            ->postJson("http://{$host}/events/{$eventA->id}/polls", [
                'question' => 'Question for Event A',
                'type' => EventPoll::TYPE_OPEN,
            ], ['HTTP_HOST' => $host]);
        $resA->assertStatus(200);

        // Event B is still locked
        $resB = $this->actingAs($user)
            ->postJson("http://{$host}/events/{$eventB->id}/polls", [
                'question' => 'Question for Event B',
                'type' => EventPoll::TYPE_OPEN,
            ], ['HTTP_HOST' => $host]);
        $resB->assertStatus(403);
    }

    public function test_monthly_live_polling_addon_unlocks_live_polling_across_workspace(): void
    {
        [$tenant, $user] = $this->createTenantOnPackage('starter', 'starter-workspace-poll');
        $eventA = Event::factory()->create(['tenant_id' => $tenant->id]);
        $eventB = Event::factory()->create(['tenant_id' => $tenant->id]);

        TenantAddon::factory()->livePollingMonthly()->create([
            'tenant_id' => $tenant->id,
        ]);

        expect($tenant->canUseLivePolling($eventA))->toBeTrue();
        expect($tenant->canUseLivePolling($eventB))->toBeTrue();
    }

    public function test_team_seats_limit_is_strictly_enforced_and_expanded_by_purchased_seats(): void
    {
        [$tenant, $admin, $host] = $this->createTenantOnPackage('starter', 'starter-seats-test');

        expect($tenant->featureLimitValue('team_seats'))->toBe(3);
        expect($tenant->totalTeamSeatLimit())->toBe(3);

        $role = Role::findByName('Org Admin', 'web');

        // Add 2nd user -> Success
        $res1 = $this->actingAs($admin)
            ->post("http://{$host}/users", [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john@test.com',
                'phone_no' => '+233241234567',
                'status' => 'active',
                'role' => $role->id,
            ], ['HTTP_HOST' => $host]);
        $res1->assertSessionHasNoErrors();
        expect($tenant->users()->count())->toBe(2);

        // Add 3rd user -> Success
        $res2 = $this->actingAs($admin)
            ->post("http://{$host}/users", [
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'email' => 'jane@test.com',
                'phone_no' => '+233241234568',
                'status' => 'active',
                'role' => $role->id,
            ], ['HTTP_HOST' => $host]);
        $res2->assertSessionHasNoErrors();
        expect($tenant->users()->count())->toBe(3);

        // Attempt 4th user -> Blocked by seat limit!
        $res3 = $this->actingAs($admin)
            ->post("http://{$host}/users", [
                'first_name' => 'Max',
                'last_name' => 'Extra',
                'email' => 'max@test.com',
                'phone_no' => '+233241234569',
                'status' => 'active',
                'role' => $role->id,
            ], ['HTTP_HOST' => $host]);
        $res3->assertSessionHasErrors('email');
        expect($tenant->users()->count())->toBe(3);

        // Purchase 2 additional team seats
        TenantAddon::factory()->teamSeat(2)->create([
            'tenant_id' => $tenant->id,
        ]);

        // Capacity expanded to 5!
        expect($tenant->purchasedTeamSeatsCount())->toBe(2);
        expect($tenant->totalTeamSeatLimit())->toBe(5);

        // Now adding 4th user succeeds!
        $res4 = $this->actingAs($admin)
            ->post("http://{$host}/users", [
                'first_name' => 'Max',
                'last_name' => 'Extra',
                'email' => 'max@test.com',
                'phone_no' => '+233241234569',
                'status' => 'active',
                'role' => $role->id,
            ], ['HTTP_HOST' => $host]);
        $res4->assertSessionHasNoErrors();
        expect($tenant->users()->count())->toBe(4);
    }

    public function test_addon_service_initializes_checkout_and_fulfills_idempotently(): void
    {
        [$tenant, $user] = $this->createTenantOnPackage('starter', 'starter-checkout-test');

        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('createOneTimeCheckoutSession')
            ->once()
            ->with(
                'CUS_TEST123',
                15000,
                Mockery::any(),
                Mockery::any(),
                Mockery::on(fn ($meta) => ($meta['type'] ?? '') === 'tenant_addon' && ($meta['addon_key'] ?? '') === 'team_seat')
            )
            ->andReturn('https://paystack.com/checkout/addon-test');

        $this->swap(PaymentGateway::class, $gateway);
        $service = app(TenantAddonService::class);

        // Test checkout initialization
        $init = $service->initializeCheckout($tenant, $user, 'team_seat', ['multiplier' => 2]);
        expect($init)->toHaveKey('checkout_url');
        expect($init['checkout_url'])->toBe('https://paystack.com/checkout/addon-test');

        // Test idempotent fulfillment
        $ref = 'ADDON-TEST-1234';
        $metadata = [
            'addon_key' => 'team_seat',
            'multiplier' => 2,
            'quantity' => 2,
            'total_price' => 15000,
        ];

        $addon1 = $service->fulfillAddonPurchase($tenant, $ref, $metadata);
        expect($addon1)->not->toBeNull();
        expect($addon1->addon_type)->toBe(TenantAddon::TYPE_TEAM_SEAT);
        expect($addon1->quantity)->toBe(2);
        expect($addon1->total_price)->toBe(15000);
        expect(Transaction::where('provider_transaction_id', $ref)->exists())->toBeTrue();

        // Second call with same reference returns existing row (no duplicates)
        $addon2 = $service->fulfillAddonPurchase($tenant, $ref, $metadata);
        expect($addon2->id)->toBe($addon1->id);
        expect(TenantAddon::where('paystack_reference', $ref)->count())->toBe(1);
        expect(Transaction::where('provider_transaction_id', $ref)->count())->toBe(1);
    }

    public function test_prepaid_sms_pack_increases_effective_tenant_capacity(): void
    {
        [$tenant] = $this->createTenantOnPackage('starter', 'starter-sms-test'); // base sms_credits: 150
        expect($tenant->featureLimitValue('sms_credits'))->toBe(150);

        TenantAddon::factory()->create([
            'tenant_id' => $tenant->id,
            'addon_type' => TenantAddon::TYPE_SMS_PACK,
            'quantity' => 500,
            'unit_price' => 3500,
            'total_price' => 3500,
            'billing_interval' => TenantAddon::INTERVAL_ONE_OFF,
            'status' => TenantAddon::STATUS_ACTIVE,
        ]);

        expect($tenant->featureLimitValue('sms_credits'))->toBe(650);
        expect($tenant->canUse('sms_credits', 600))->toBeTrue();
        expect($tenant->canUse('sms_credits', 700))->toBeFalse();
    }

    public function test_cancelling_an_addon_updates_status(): void
    {
        [$tenant] = $this->createTenantOnPackage('starter', 'starter-cancel-test');
        $addon = TenantAddon::factory()->teamSeat(1)->create([
            'tenant_id' => $tenant->id,
        ]);

        $service = app(TenantAddonService::class);
        $service->cancelAddon($tenant, $addon);

        $addon->refresh();
        expect($addon->status)->toBe(TenantAddon::STATUS_CANCELLED);
    }

    public function test_cross_tenant_isolation_on_addons(): void
    {
        [$tenantA] = $this->createTenantOnPackage('starter', 'tenant-a-test');
        [$tenantB, $userB, $hostB] = $this->createTenantOnPackage('starter', 'tenant-b-test');

        $addonA = TenantAddon::factory()->teamSeat(1)->create([
            'tenant_id' => $tenantA->id,
        ]);

        // Tenant B cannot cancel Tenant A's addon
        $response = $this->actingAs($userB)
            ->post("http://{$hostB}/billing/addons/{$addonA->id}/cancel", [], ['HTTP_HOST' => $hostB]);

        $response->assertStatus(403);
        $addonA->refresh();
        expect($addonA->status)->toBe(TenantAddon::STATUS_ACTIVE);
    }

    public function test_cancelled_recurring_addon_remains_active_until_period_end_then_expires(): void
    {
        [$tenant, $user, $host] = $this->createTenantOnPackage('starter', 'grace-period-test');
        $event = Event::factory()->create(['tenant_id' => $tenant->id]);

        $addon = TenantAddon::factory()->livePollingMonthly()->create([
            'tenant_id' => $tenant->id,
            'period_end' => now()->addDays(20),
        ]);

        expect($tenant->canUseLivePolling($event))->toBeTrue();

        // Cancel addon via endpoint
        $this->actingAs($user)
            ->post("http://{$host}/billing/addons/{$addon->id}/cancel", [], ['HTTP_HOST' => $host])
            ->assertRedirect();

        $addon->refresh();
        expect($addon->status)->toBe(TenantAddon::STATUS_CANCELLED);

        // While period_end is in the future, it is STILL active!
        expect($addon->isActive())->toBeTrue();
        expect($tenant->canUseLivePolling($event))->toBeTrue();

        // Fast forward past period_end -> Now expired!
        $this->travelTo(now()->addDays(25));
        expect($addon->isActive())->toBeFalse();
        expect($tenant->canUseLivePolling($event))->toBeFalse();
    }

    public function test_cannot_cancel_non_recurring_one_off_packs(): void
    {
        [$tenant, $user, $host] = $this->createTenantOnPackage('starter', 'non-recurring-cancel');

        $smsAddon = TenantAddon::create([
            'tenant_id' => $tenant->id,
            'addon_type' => TenantAddon::TYPE_SMS_PACK,
            'name' => '500 SMS Pack',
            'quantity' => 500,
            'unit_price' => 3500,
            'total_price' => 3500,
            'billing_interval' => TenantAddon::INTERVAL_ONE_OFF,
            'status' => TenantAddon::STATUS_ACTIVE,
        ]);

        $res = $this->actingAs($user)
            ->post("http://{$host}/billing/addons/{$smsAddon->id}/cancel", [], ['HTTP_HOST' => $host]);

        $res->assertStatus(422);
        expect($smsAddon->fresh()->status)->toBe(TenantAddon::STATUS_ACTIVE);
    }

    public function test_presentation_results_endpoint_is_strictly_gated_by_can_use_live_polling(): void
    {
        [$tenant, $user, $host] = $this->createTenantOnPackage('starter', 'present-results-gate');
        $event = Event::factory()->create([
            'tenant_id' => $tenant->id,
            'present_token' => 'SECRET_PRESENTER_TOKEN_123',
        ]);

        // Attempting to fetch presentation results without live polling entitlement fails with 403
        $response = $this->getJson("http://{$host}/e/{$event->slug}/present/SECRET_PRESENTER_TOKEN_123/results", ['HTTP_HOST' => $host]);
        $response->assertStatus(403);
    }

    public function test_checkout_rejects_invalid_addon_key(): void
    {
        [$tenant, $user, $host] = $this->createTenantOnPackage('starter', 'invalid-key-test');

        $response = $this->actingAs($user)
            ->post("http://{$host}/billing/addons/checkout", [
                'addon_key' => 'totally_fake_addon_key',
            ], ['HTTP_HOST' => $host]);

        $response->assertSessionHasErrors('addon_key');
    }

    public function test_checkout_accepts_a_catalog_addon_key(): void
    {
        [$tenant, $user, $host] = $this->createTenantOnPackage('starter', 'real-key-test');
        config(['services.payment.dev_bypass' => false]);

        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('createCustomer')->andReturn('CUS_TEST123');
        $gateway->shouldReceive('createOneTimeCheckoutSession')->once()->andReturn('https://paystack.com/checkout/real-key');
        $this->swap(PaymentGateway::class, $gateway);

        // Validation once compared the key against the catalog's list
        // positions (0, 1, 2...), so every real purchase was refused.
        $this->actingAs($user)
            ->post("http://{$host}/billing/addons/checkout", [
                'addon_key' => 'team_seat',
            ], ['HTTP_HOST' => $host])
            ->assertSessionHasNoErrors()
            ->assertRedirect('https://paystack.com/checkout/real-key');
    }

    public function test_addons_not_yet_delivered_cannot_be_bought(): void
    {
        [$tenant, $user, $host] = $this->createTenantOnPackage('starter', 'unavailable-addon-test');

        foreach (['sms_500', 'sms_1500', 'sms_5000'] as $key) {
            $this->actingAs($user)
                ->post("http://{$host}/billing/addons/checkout", [
                    'addon_key' => $key,
                ], ['HTTP_HOST' => $host])
                ->assertSessionHasErrors('addon_key');
        }

        expect(fn () => app(TenantAddonService::class)->initializeCheckout($tenant, $user, 'sms_500'))
            ->toThrow(InvalidArgumentException::class);

        $listed = collect(app(TenantAddonService::class)->getCatalog())->keyBy('key');
        expect($listed['sms_500']['available'])->toBeFalse()
            ->and($listed['team_seat']['available'])->toBeTrue();
    }

    private function createTenantOnPackage(string $slug, string $tenantSlug): array
    {
        $package = Package::where('slug', $slug)->firstOrFail();
        $tenant = Tenant::factory()->create([
            'slug' => $tenantSlug,
            'package_id' => $package->id,
            'status' => 'active',
            'isolation_mode' => 'shared',
            'meta' => ['paystack_id' => 'CUS_TEST123'],
        ]);
        $tenant->syncFeaturesFromPackage();

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        setPermissionsTeamId($tenant->id);
        $user->assignRole('Org Superadmin');
        $tenant->users()->attach($user->id);

        $host = eventSubdomainHost($tenant->slug);

        return [$tenant, $user, $host];
    }
}
