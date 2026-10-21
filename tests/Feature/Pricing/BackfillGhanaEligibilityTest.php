<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantPayoutAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Pricing\GhanaEligibility;
use Illuminate\Support\Facades\Cache;

/**
 * Existing customers who have already paid with Ghana mobile money or a Ghana
 * card, or hold a verified Ghana payout account, are marked Ghana eligible when
 * this ships. Nobody else is touched, and running it again changes nothing.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Cache::flush();

    $this->paid = fn (Tenant $tenant, array $origin, string $type = 'plan_subscription'): Transaction => Transaction::factory()->create([
        'tenant_id' => $tenant->id, 'provider' => 'paystack', 'currency' => 'ghs', 'meta' => ['type' => $type, 'payment_origin' => $origin],
    ]);
    $this->momo = Tenant::factory()->create(['slug' => 'momo-org']);
    ($this->paid)($this->momo, ['channel' => 'mobile_money', 'country_code' => 'GH']);
    $this->foreignCard = Tenant::factory()->create(['slug' => 'foreign-card-org']);
    ($this->paid)($this->foreignCard, ['channel' => 'card', 'country_code' => 'NG']);
    $this->payout = Tenant::factory()->create(['slug' => 'payout-org']);
    TenantPayoutAccount::factory()->create(['tenant_id' => $this->payout->id, 'is_verified' => true]);
    $this->nothing = Tenant::factory()->create(['slug' => 'no-evidence-org']);
});

test('a dry run reports who would be marked and changes nothing', function (): void {
    $this->artisan('pricing:backfill-ghana', ['--dry-run' => true])
        ->expectsOutputToContain('Would mark 2 organisation(s) Ghana eligible (ghana_payment 1, payout_account 1).')
        ->assertSuccessful();

    expect(Tenant::query()->whereNotNull('pricing_region')->count())->toBe(0);
});

test('it marks organisations with a Ghana payment or a verified payout account, and no one else', function (): void {
    $this->artisan('pricing:backfill-ghana')->expectsOutputToContain('Marked 2')->assertSuccessful();

    expect($this->momo->fresh()->isGhanaEligible())->toBeTrue()
        ->and($this->momo->fresh()->pricing_region_evidence['source'])->toBe('ghana_payment')
        ->and($this->payout->fresh()->isGhanaEligible())->toBeTrue()
        ->and($this->payout->fresh()->pricing_region_evidence['source'])->toBe('payout_account')
        ->and($this->foreignCard->fresh()->pricing_region)->toBeNull()
        ->and($this->nothing->fresh()->pricing_region)->toBeNull();
});

test('a second run changes nothing', function (): void {
    $this->artisan('pricing:backfill-ghana')->assertSuccessful();
    $before = Tenant::query()->orderBy('slug')->get(['slug', 'pricing_region', 'pricing_region_evidence'])->toArray();

    $this->artisan('pricing:backfill-ghana')->expectsOutputToContain('Marked 0')->assertSuccessful();

    expect(Tenant::query()->orderBy('slug')->get(['slug', 'pricing_region', 'pricing_region_evidence'])->toArray())->toBe($before);
});

test('an organisation settled in USD is not upgraded by a payment, but is by a verified payout account', function (): void {
    $this->momo->update(['pricing_region' => 'INTL']);
    $this->payout->update(['pricing_region' => 'INTL']);

    $this->artisan('pricing:backfill-ghana')->expectsOutputToContain('Marked 1')->assertSuccessful();

    expect($this->momo->fresh()->pricing_region)->toBe('INTL')->and($this->payout->fresh()->pricing_region)->toBe('GH');
});

test('an organisation a superadmin removed from Ghana pricing stays removed', function (): void {
    app(GhanaEligibility::class)->revoke($this->momo, User::factory()->create(['tenant_id' => null]), 'Abuse');

    $this->artisan('pricing:backfill-ghana')->assertSuccessful();

    expect($this->momo->fresh()->isGhanaEligible())->toBeFalse();
});

test('an organisation own ticket sales are not evidence', function (): void {
    ($this->paid)($this->nothing, ['channel' => 'mobile_money', 'country_code' => 'GH'], 'event_ticket');

    $this->artisan('pricing:backfill-ghana')->assertSuccessful();

    expect($this->nothing->fresh()->pricing_region)->toBeNull();
});
