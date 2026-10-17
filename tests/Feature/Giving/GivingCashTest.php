<?php

declare(strict_types=1);

use App\Models\GivingGift;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Services\Giving\GivingCash;
use App\Services\Giving\GivingProgress;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\GivingScenario;

test('cash and offering batches are idempotent and never touch either ledger', function () {
    [$tenant, $user, $fund] = GivingScenario::make();
    $cash = app(GivingCash::class);
    $before = [LedgerTransaction::query()->get()->toArray(), LedgerEntry::query()->get()->toArray(), App\Models\EventLedgerEntry::query()->get()->toArray()];
    $key = (string) Str::uuid();
    $gift = $cash->record($fund, $user, ['source' => 'bulk_cash', 'amount' => 5000, 'currency' => 'GHS', 'collected_at' => '2026-10-17 10:00'], $key);
    expect($cash->record($fund, $user, ['source' => 'bulk_cash', 'amount' => 5000, 'currency' => 'GHS', 'collected_at' => '2026-10-17 10:00'], $key)->id)->toBe($gift->id)
        ->and($gift->giver_key)->toBeNull()->and($gift->commission_amount)->toBe(0)
        ->and($gift->collected_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-17 10:00');
    expect([LedgerTransaction::query()->get()->toArray(), LedgerEntry::query()->get()->toArray(), App\Models\EventLedgerEntry::query()->get()->toArray()])->toBe($before);
    expect(GivingGift::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);
    expect(fn () => $cash->record($fund, $user, ['source' => 'bulk_cash', 'amount' => 6000, 'currency' => 'GHS'], $key))->toThrow(ValidationException::class);
});

test('cash corrections append history without changing the original gift and cannot exceed it', function () {
    [, $user, $fund] = GivingScenario::make();
    $cash = app(GivingCash::class);
    $gift = $cash->record($fund, $user, ['source' => 'recorded_cash', 'payer_name' => 'A giver', 'amount' => 5000, 'currency' => 'GHS'], (string) Str::uuid());
    $original = $gift->fresh()->getRawOriginal();
    $key = (string) Str::uuid();
    $adjustment = $cash->correct($gift, $user, 2000, 'Counting correction', $key);
    expect($adjustment->status)->toBe('confirmed')->and($cash->correct($gift, $user, 2000, 'Counting correction', $key)->id)->toBe($adjustment->id)
        ->and($gift->fresh()->getRawOriginal())->toBe($original)
        ->and(app(GivingProgress::class)->for($fund)['raised'])->toBe(3000)
        ->and(LedgerTransaction::query()->count())->toBe(0);
    expect(fn () => $cash->correct($gift, $user, 3001, 'Too much', (string) Str::uuid()))->toThrow(ValidationException::class);
});

test('cash requires finance permission and rejects mismatched currency and foreign member attribution', function () {
    [$tenant, $user, $fund] = GivingScenario::make();
    $cash = app(GivingCash::class);
    expect(fn () => $cash->record($fund, $user, ['amount' => 5000, 'currency' => 'USD', 'source' => 'bulk_cash'], (string) Str::uuid()))->toThrow(ValidationException::class);
    $user->givePermissionTo('manage members');
    $foreign = App\Models\Member::factory()->create();
    expect(fn () => $cash->record($fund, $user, ['amount' => 5000, 'currency' => 'GHS', 'source' => 'recorded_cash', 'member_id' => $foreign->id], (string) Str::uuid()))->toThrow(ValidationException::class);
    $user->revokePermissionTo('read finance');
    expect(fn () => $cash->record($fund, $user, ['amount' => 5000, 'currency' => 'GHS', 'source' => 'bulk_cash'], (string) Str::uuid()))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
});

test('bulk cash cannot retain a donor identity and record and correction routes enforce isolation', function () {
    [$tenant, $user, $fund] = GivingScenario::make();
    $host = eventSubdomainHost($tenant->slug);
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => $host]);
    $url = 'http://'.$host.'/community/money/giving/funds/'.$fund->id.'/gifts';
    $this->postJson($url, ['source' => 'bulk_cash', 'amount' => 5000, 'currency' => 'GHS', 'payer_email' => 'person@example.test', 'submission_key' => (string) Str::uuid()])->assertUnprocessable();
    $response = $this->postJson($url, ['source' => 'bulk_cash', 'amount' => 5000, 'currency' => 'GHS', 'submission_key' => (string) Str::uuid()])->assertCreated();
    $id = $response->json('gift.id');
    $this->postJson('http://'.$host.'/community/money/giving/gifts/'.$id.'/corrections', ['principal' => 1000, 'reason' => 'Correction', 'operation_key' => (string) Str::uuid()])->assertCreated();
    $other = GivingGift::factory()->create(['source' => 'recorded_cash', 'status' => 'completed']);
    $this->postJson('http://'.$host.'/community/money/giving/gifts/'.$other->id.'/corrections', ['principal' => 1000, 'reason' => 'Foreign', 'operation_key' => (string) Str::uuid()])->assertNotFound();
});
