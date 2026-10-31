<?php

declare(strict_types=1);

use App\Enum\LedgerCategoryEnum;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Services\Marketplace\StorePaymentConfirmer;
use App\Services\Marketplace\StorePaymentEvidence;
use Tests\Support\StoreCheckoutScenario;

it('posts net cash platform revenue and seller payable into the isolated commerce category', function (): void {
    [$tenant, , , , , $attempt] = StoreCheckoutScenario::online();
    $capture = app(StorePaymentEvidence::class)->record($attempt, StoreCheckoutScenario::evidence($attempt));
    app(StorePaymentConfirmer::class)->confirm($capture);
    $txn = LedgerTransaction::withoutGlobalScopes()->where('reference', $attempt->reference)->firstOrFail();
    expect($txn->category)->toBe(LedgerCategoryEnum::Commerce)->and($txn->event_id)->toBeNull();
    $entries = $txn->entries()->with('account')->get();
    $byCode = $entries->keyBy(fn ($entry) => $entry->account->code);
    expect($byCode[LedgerAccount::CODE_GATEWAY_CLEARING]->amount)->toBe(9805)
        ->and($byCode[LedgerAccount::CODE_PLATFORM_REVENUE]->amount)->toBe(200)
        ->and($byCode[LedgerAccount::CODE_ORGANIZER_PAYABLE]->amount)->toBe(9605)
        ->and($entries->where('direction', LedgerEntry::DIRECTION_DEBIT)->sum('amount'))->toBe($entries->where('direction', LedgerEntry::DIRECTION_CREDIT)->sum('amount'));
    expect(LedgerAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('category', 'marketplace')->count())->toBe(0);
});

it('forbids event attribution on the commerce ledger category', function (): void {
    $event = App\Models\Event::factory()->create();
    app(App\Services\Finance\LedgerService::class)->ensureAccountsExist($event->tenant, $event, 'GHS', LedgerCategoryEnum::Commerce);
})->throws(InvalidArgumentException::class);
