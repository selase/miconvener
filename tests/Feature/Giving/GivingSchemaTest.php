<?php

declare(strict_types=1);

use App\Enum\LedgerCategoryEnum;
use App\Models\GivingFund;
use App\Models\GivingGift;
use App\Models\Tenant;
use App\Services\Giving\GivingRollout;
use Illuminate\Support\Facades\Schema;

test('giving schema exists without changing event contribution ownership', function () {
    foreach (['giving_funds', 'giving_gifts', 'giving_contribution_links', 'giving_payment_attempts', 'giving_adjustments', 'giving_receipt_notices', 'giving_reminders'] as $table) {
        expect(Schema::connection('landlord')->hasTable($table))->toBeTrue($table);
    }
    expect(LedgerCategoryEnum::Giving->allowsEvent(false))->toBeTrue()
        ->and(LedgerCategoryEnum::Giving->allowsEvent(true))->toBeFalse();
});

test('giving contacts are encrypted and excluded from automatic serialization', function () {
    $fund = GivingFund::factory()->create();
    $gift = GivingGift::factory()->create(['tenant_id' => $fund->tenant_id, 'fund_id' => $fund->id, 'payer_email' => 'giver@example.test']);
    expect($gift->payer_email)->toBe('giver@example.test')
        ->and($gift->getRawOriginal('payer_email'))->not->toContain('giver@example.test')
        ->and($gift->toArray())->not->toHaveKey('payer_email');
});

test('cash history keeps finance accessible after giving and community are disabled', function () {
    $tenant = Tenant::factory()->create();
    $fund = GivingFund::factory()->create(['tenant_id' => $tenant->id]);
    GivingGift::factory()->create(['tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'source' => 'bulk_cash', 'status' => 'completed']);
    expect($tenant->hasGivingMoney())->toBeTrue()->and($tenant->showsFinance())->toBeTrue()
        ->and(app(GivingRollout::class)->enabled($tenant))->toBeFalse();
});

test('online readiness is independent and enabling preserves unrelated metadata', function () {
    $tenant = Tenant::factory()->create(['slug' => 'giving-pilot']);
    $this->artisan('community:enable-giving', ['tenant' => $tenant->slug])->assertFailed();
    $tenant->features()->create(['feature_key' => 'community', 'enabled' => true]);
    $this->artisan('community:enable-giving', ['tenant' => $tenant->slug])->assertSuccessful();
    expect(app(GivingRollout::class)->enabled($tenant))->toBeTrue()->and(app(GivingRollout::class)->onlineEnabled($tenant))->toBeFalse();
    $row = $tenant->features()->where('feature_key', 'community_giving')->firstOrFail();
    $row->update(['meta' => ['source' => 'admin_override', 'note' => 'keep']]);
    $this->artisan('community:enable-giving', ['tenant' => $tenant->slug, '--online' => true])->assertSuccessful();
    expect(app(GivingRollout::class)->onlineEnabled($tenant))->toBeTrue()->and($row->fresh()->meta['note'])->toBe('keep');
    $this->artisan('community:enable-giving', ['tenant' => $tenant->slug, '--off' => true])->assertSuccessful();
    expect(app(GivingRollout::class)->onlineEnabled($tenant))->toBeFalse();
});

test('typed giving sources reject arbitrary classes and other organisations', function () {
    $fund = GivingFund::factory()->create();
    $gift = GivingGift::factory()->create(['tenant_id' => $fund->tenant_id, 'fund_id' => $fund->id]);
    $sources = app(App\Services\Giving\GivingSources::class);
    expect($sources->find($fund->tenant, 'gift', $gift->id)->key())->toBe('gift:'.$gift->id);
    expect(fn () => $sources->find(Tenant::factory()->create(), 'gift', $gift->id))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect(fn () => $sources->find($fund->tenant, GivingGift::class, $gift->id))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('a pending Giving payment retains the recovery path when rollout switches are off', function () {
    $tenant = Tenant::factory()->create();
    $fund = GivingFund::factory()->create(['tenant_id' => $tenant->id]);
    GivingGift::factory()->create(['tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'source' => 'online', 'status' => 'pending']);
    expect($tenant->hasGivingMoney())->toBeTrue()->and($tenant->showsFinance())->toBeTrue();
});
