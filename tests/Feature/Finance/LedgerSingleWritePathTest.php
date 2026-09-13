<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventLedgerEntry;
use App\Models\EventRegistration;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Tenant;
use App\Services\Finance\LedgerService;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function payableBalanceFor(Event $event): int
{
    $account = LedgerAccount::on('landlord')
        ->where('event_id', $event->id)
        ->where('code', LedgerAccount::CODE_ORGANIZER_PAYABLE)
        ->firstOrFail();

    return (int) LedgerEntry::on('landlord')->where('account_id', $account->id)
        ->where('direction', LedgerEntry::DIRECTION_CREDIT)->sum('amount')
        - (int) LedgerEntry::on('landlord')->where('account_id', $account->id)
            ->where('direction', LedgerEntry::DIRECTION_DEBIT)->sum('amount');
}

function singleWriteFixture(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared', 'package_id' => null]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'amount' => 10000,
    ]);

    return [$event, $registration];
}

test('one recordTicketSale call writes both ledgers and they agree', function () {
    [$event, $registration] = singleWriteFixture('single-write-1');

    app(LedgerService::class)->recordTicketSale($event, $registration, 10000, 200, 'SALE-REF-1', 195);

    $old = EventLedgerEntry::on('landlord')
        ->where('registration_id', $registration->id)
        ->where('type', EventLedgerEntry::TYPE_CHARGE)
        ->sole();

    expect($old->gross_amount)->toBe(10000)
        ->and($old->gateway_fee_amount)->toBe(195)
        ->and($old->commission_amount)->toBe(200)
        ->and($old->net_amount)->toBe(9605);

    // The same figure, arrived at independently through the double-entry side.
    expect(payableBalanceFor($event))->toBe($old->net_amount);
});

test('a sale writes exactly one old-ledger row, not two', function () {
    [$event, $registration] = singleWriteFixture('single-write-2');

    app(LedgerService::class)->recordTicketSale($event, $registration, 10000, 200, 'SALE-REF-2', 195);

    // If a caller's hand-written create survived the consolidation, this is 2.
    expect(EventLedgerEntry::on('landlord')->where('registration_id', $registration->id)->count())->toBe(1);
});

test('a payout writes the settlement-statement row as well', function () {
    [$event] = singleWriteFixture('single-write-3');

    app(LedgerService::class)->recordPayout($event, 9605, 'PAYOUT-REF-3', null, 100, false);

    $old = EventLedgerEntry::on('landlord')
        ->where('event_id', $event->id)
        ->where('type', EventLedgerEntry::TYPE_PAYOUT)
        ->sole();

    expect($old->gross_amount)->toBe(9605);
});

test('no controller or command writes EventLedgerEntry directly any more', function () {
    $offenders = [];

    foreach (['app/Http/Controllers', 'app/Console/Commands', 'app/Jobs'] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir)));

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php'
                && str_contains((string) file_get_contents($file->getPathname()), 'EventLedgerEntry::create')) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }

    expect($offenders)->toBe([]);
});
