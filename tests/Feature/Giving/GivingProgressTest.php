<?php

declare(strict_types=1);

use App\Models\GivingGift;
use App\Services\Giving\GivingCash;
use App\Services\Giving\GivingProgress;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\GivingScenario;

test('campaign progress combines principal and cash with no fee subtraction or bulk donor count', function () {
    [, $user, $fund] = GivingScenario::make(['kind' => 'campaign', 'target' => 100000]);
    GivingGift::factory()->create(['tenant_id' => $fund->tenant_id, 'fund_id' => $fund->id, 'amount' => 10000, 'commission_amount' => 100, 'gateway_fee_amount' => 200, 'net_amount' => 9700, 'status' => 'completed', 'giver_key' => 'verified-fixture']);
    app(GivingCash::class)->record($fund, $user, ['source' => 'bulk_cash', 'amount' => 5000, 'currency' => 'GHS'], (string) Str::uuid());
    expect(app(GivingProgress::class)->for($fund))->toMatchArray(['raised' => 15000, 'left' => 85000, 'percent' => 15.0, 'identified_givers' => 1, 'target_reached' => false]);
});

test('reused identified donor keys deduplicate but names and shared phones never merge', function () {
    [, $user, $fund] = GivingScenario::make();
    $cash = app(GivingCash::class);
    $input = ['source' => 'recorded_cash', 'amount' => 1000, 'currency' => 'GHS', 'payer_name' => 'Same name', 'payer_phone' => '+233241234567'];
    $first = $cash->record($fund, $user, $input, (string) Str::uuid());
    $cash->record($fund, $user, [...$input, 'is_anonymous' => true, 'reuse_giver_key' => $first->giver_key], (string) Str::uuid());
    expect(app(GivingProgress::class)->for($fund)['identified_givers'])->toBe(1);
    $cash->record($fund, $user, $input, (string) Str::uuid());
    expect(app(GivingProgress::class)->for($fund)['identified_givers'])->toBe(2);
});

test('a verified email key is shared with an explicitly finance-confirmed cash email', function () {
    [, $user, $fund] = GivingScenario::make();
    $key = App\Services\Giving\GivingIdentity::emailKey($fund->tenant, 'Giver@Example.test');
    GivingGift::factory()->create(['tenant_id' => $fund->tenant_id, 'fund_id' => $fund->id, 'status' => 'completed', 'giver_key' => $key]);
    app(GivingCash::class)->record($fund, $user, ['source' => 'recorded_cash', 'amount' => 1000, 'currency' => 'GHS', 'payer_email' => ' giver@example.test ', 'identity_confirmed' => true, 'is_anonymous' => true], (string) Str::uuid());
    expect(app(GivingProgress::class)->for($fund)['identified_givers'])->toBe(1);
});

test('open funds have no fabricated target and unverified guest input has no giver identity', function () {
    [, , $fund] = GivingScenario::make();
    GivingGift::factory()->create(['tenant_id' => $fund->tenant_id, 'fund_id' => $fund->id, 'amount' => 1000, 'status' => 'completed', 'payer_email' => 'unverified@example.test']);
    expect(app(GivingProgress::class)->for($fund))->toMatchArray(['raised' => 1000, 'left' => null, 'percent' => null, 'identified_givers' => 0, 'target_reached' => false]);
});

test('exceeding the target stays open unless auto-close was chosen and corrections do not reopen', function (bool $autoClose) {
    [, $user, $fund] = GivingScenario::make(['kind' => 'campaign', 'target' => 100000, 'close_on_target' => $autoClose]);
    $cash = app(GivingCash::class);
    $gift = $cash->record($fund, $user, ['source' => 'bulk_cash', 'amount' => 110000, 'currency' => 'GHS'], (string) Str::uuid());
    expect(app(GivingProgress::class)->for($fund))->toMatchArray(['raised' => 110000, 'left' => 0, 'percent' => 110.0, 'target_reached' => true]);
    expect($fund->fresh()->state)->toBe($autoClose ? 'closed' : 'open');
    if ($autoClose) {
        expect(fn () => $cash->record($fund, $user, ['source' => 'bulk_cash', 'amount' => 1000, 'currency' => 'GHS'], (string) Str::uuid()))->toThrow(ValidationException::class);
    }
    $cash->correct($gift, $user, 20000, 'Correction', (string) Str::uuid());
    expect($fund->fresh()->state)->toBe($autoClose ? 'closed' : 'open');
})->with([false, true]);

test('a deadline closes new starts at the end of the organisations local day', function () {
    [$tenant, $user, $fund] = GivingScenario::make(['ends_on' => '2026-10-17']);
    App\Models\CommunitySettings::query()->create(['tenant_id' => $tenant->id, 'timezone' => 'America/New_York']);
    $cash = app(GivingCash::class);
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-10-18 03:59:59 UTC'));
    $cash->record($fund, $user, ['source' => 'bulk_cash', 'amount' => 1000, 'currency' => 'GHS'], (string) Str::uuid());
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-10-18 04:00:00 UTC'));
    expect(fn () => $cash->record($fund, $user, ['source' => 'bulk_cash', 'amount' => 1000, 'currency' => 'GHS'], (string) Str::uuid()))->toThrow(ValidationException::class);
});
