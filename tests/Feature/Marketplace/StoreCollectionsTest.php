<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\StoreCollection;
use App\Models\StoreCollectionPass;
use App\Services\Marketplace\StoreCollectionPasses;
use App\Services\Marketplace\StoreCollections;
use App\Services\Marketplace\StoreRefunds;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\StoreCheckoutScenario;

it('confirms one whole order without another stock deduction or ledger posting', function () {
    [, $variant,$actor,$session,$order] = StoreCheckoutScenario::collectionReady();
    $proof = app(StoreCollectionPasses::class)->issue($order, $session, (string) Str::uuid());
    $ledger = App\Models\LedgerTransaction::withoutGlobalScopes()->count();
    $key = (string) Str::uuid();
    $credential = ['manual_code' => $proof['manual_code']];
    $record = app(StoreCollections::class)->confirm($order, $actor, $credential, $key);
    expect($order->fresh()->fulfilment_state)->toBe('collected')->and($variant->fresh()->on_hand)->toBe(2)
        ->and(App\Models\LedgerTransaction::withoutGlobalScopes()->count())->toBe($ledger)
        ->and(StoreCollectionPass::query()->findOrFail($proof['pass_id'])->consumed_at)->not->toBeNull();
    $this->travel(1)->days();
    app(StoreCollectionPasses::class)->purge();
    expect(app(StoreCollections::class)->confirm($order, $actor, $credential, $key)->id)->toBe($record->id)
        ->and(StoreCollection::query()->count())->toBe(1);
    expect(fn () => app(StoreCollections::class)->confirm($order, $actor, ['manual_code' => '000000000000'], $key))->toThrow(ValidationException::class);
    expect(fn () => app(StoreCollections::class)->confirm($order, $actor, $credential, (string) Str::uuid()))->toThrow(ValidationException::class);
});

it('blocks collection immediately when a primary refund is claimed even without available funding', function () {
    [$tenant,,$actor,$session,$order] = StoreCheckoutScenario::collectionReady();
    Permission::query()->firstOrCreate(['name' => 'manage store finance', 'guard_name' => 'web'], ['category' => 'store']);
    $actor->givePermissionTo('manage store finance');
    $proof = app(StoreCollectionPasses::class)->issue($order, $session, (string) Str::uuid());
    Illuminate\Support\Facades\Http::fake(['*api.paystack.co/refund' => Illuminate\Support\Facades\Http::response(['data' => ['id' => 'refund-test', 'status' => 'pending']])]);
    app(StoreRefunds::class)->request($order, $actor, (string) Str::uuid(), 'Buyer cancelled');
    expect(fn () => app(StoreCollections::class)->confirm($order, $actor, ['manual_code' => $proof['manual_code']], (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(StoreCollectionPass::query()->findOrFail($proof['pass_id'])->revoked_at)->not->toBeNull();
});

it('rejects a primary refund after confirmed collection and preserves the handover evidence', function () {
    [,,$actor,$session,$order] = StoreCheckoutScenario::collectionReady();
    Permission::query()->firstOrCreate(['name' => 'manage store finance', 'guard_name' => 'web'], ['category' => 'store']);
    $actor->givePermissionTo('manage store finance');
    $proof = app(StoreCollectionPasses::class)->issue($order, $session, (string) Str::uuid());
    app(StoreCollections::class)->confirm($order, $actor, ['manual_code' => $proof['manual_code']], (string) Str::uuid());
    expect(fn () => app(StoreRefunds::class)->request($order, $actor, (string) Str::uuid(), 'After handover'))->toThrow(ValidationException::class);
    expect(StoreCollection::query()->count())->toBe(1)->and($order->fresh()->payment_state)->toBe('paid');
});

it('still limits wrong guesses against a completed handover replay', function () {
    [,,$actor,$session,$order] = StoreCheckoutScenario::collectionReady();
    $proof = app(StoreCollectionPasses::class)->issue($order, $session, (string) Str::uuid());
    $key = (string) Str::uuid();
    $service = app(StoreCollections::class);
    $service->confirm($order, $actor, ['manual_code' => $proof['manual_code']], $key);
    for ($i = 0; $i < 5; $i++) {
        expect(fn () => $service->confirm($order, $actor, ['manual_code' => '000000000000'], $key))->toThrow(ValidationException::class);
    }
    expect(fn () => $service->confirm($order, $actor, ['manual_code' => $proof['manual_code']], $key))->toThrow(ValidationException::class);
});
