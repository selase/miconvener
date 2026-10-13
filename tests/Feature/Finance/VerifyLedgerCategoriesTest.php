<?php

declare(strict_types=1);

use App\Enum\LedgerCategoryEnum;
use App\Models\Event;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Tenant;
use App\Services\Finance\LedgerService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->event = Event::factory()->create(['tenant_id' => $this->tenant->id, 'currency' => 'GHS']);
    $this->ledger = app(LedgerService::class);
});

function postedEntries(): array
{
    return [
        ['code' => LedgerAccount::CODE_GATEWAY_CLEARING, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => 1000],
        ['code' => LedgerAccount::CODE_ORGANIZER_PAYABLE, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => 1000],
    ];
}

test('a consistent ledger passes and reports its counts', function (): void {
    $this->ledger->postTransaction($this->tenant, $this->event, 'ticket_sale', 'Sale', 'V-1', postedEntries());
    $this->ledger->postTransaction($this->tenant, null, 'adjustment', 'Dues', 'V-2', postedEntries(), null, 'GHS', LedgerCategoryEnum::Dues);

    $exitCode = Artisan::call('finance:verify-categories');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('event')->toContain('dues')->toContain('ledger_transactions');
});

test('an entry in an account of another category fails with how many', function (): void {
    $this->ledger->postTransaction($this->tenant, $this->event, 'ticket_sale', 'Sale', 'V-3', postedEntries());
    $this->ledger->postTransaction($this->tenant, null, 'adjustment', 'Dues', 'V-4', postedEntries(), null, 'GHS', LedgerCategoryEnum::Dues);

    // Move one dues entry into an event account, as a bad backfill might have.
    $eventAccount = DB::connection('landlord')->table('ledger_accounts')->where('event_id', $this->event->id)->where('code', LedgerAccount::CODE_GATEWAY_CLEARING)->value('id');
    $duesTransaction = DB::connection('landlord')->table('ledger_transactions')->where('reference', 'V-4')->value('id');
    DB::connection('landlord')->table('ledger_entries')->where('transaction_id', $duesTransaction)->where('direction', LedgerEntry::DIRECTION_DEBIT)->update(['account_id' => $eventAccount]);

    $exitCode = Artisan::call('finance:verify-categories');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('1 ledger entr');
});
