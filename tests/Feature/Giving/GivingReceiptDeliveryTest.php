<?php

declare(strict_types=1);

use App\Jobs\DeliverGivingReceipt;
use App\Mail\GivingReceipt;
use App\Models\GivingReceiptNotice;
use App\Services\Giving\GivingCash;
use App\Services\Giving\GivingPaymentConfirmer;
use App\Services\Giving\GivingReceipts;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\GivingScenario;

test('confirmed payments capture one immutable receipt and dispatch only after commit', function () {
    Queue::fake();
    [$tenant,,$fund,$attempt] = GivingScenario::online();
    $db = $attempt->getConnection();
    $level = $db->transactionLevel();
    $db->beginTransaction();
    app(GivingPaymentConfirmer::class)->confirm($attempt->reference, GivingScenario::verified($attempt));
    app(GivingPaymentConfirmer::class)->confirm($attempt->reference, GivingScenario::verified($attempt));
    Queue::assertNothingPushed();
    expect(GivingReceiptNotice::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);
    $notice = GivingReceiptNotice::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();
    $snapshot = $notice->snapshot;
    $fund->update(['name' => 'Renamed fund']);
    $db->commit();
    expect($db->transactionLevel())->toBe($level);
    Queue::assertPushed(DeliverGivingReceipt::class, 1);
    expect($notice->fresh()->snapshot)->toBe($snapshot)->and($snapshot['fund'])->not->toBe('Renamed fund');
});

test('rolled back and failed payments send nothing and leave no receipt', function () {
    Queue::fake();
    [$tenant,,,$attempt] = GivingScenario::online();
    app(GivingPaymentConfirmer::class)->confirm($attempt->reference, GivingScenario::verified($attempt, ['status' => 'failed']));
    expect(GivingReceiptNotice::withoutGlobalScopes()->count())->toBe(0);
    $attempt->getConnection()->beginTransaction();
    app(GivingPaymentConfirmer::class)->confirm($attempt->reference, GivingScenario::verified($attempt));
    $attempt->getConnection()->rollBack();
    expect(GivingReceiptNotice::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('receipt delivery is transactional unmetered retryable and never mails synthetic contacts', function () {
    Queue::fake();
    Mail::fake();
    [$tenant,$user,$fund] = GivingScenario::make();
    $gift = app(GivingCash::class)->record($fund, $user, ['source' => 'recorded_cash', 'amount' => 5000, 'currency' => 'GHS', 'payer_email' => 'giver@example.test'], (string) Str::uuid());
    $notice = GivingReceiptNotice::withoutGlobalScopes()->where('gift_id', $gift->id)->sole();
    app(GivingReceipts::class)->deliver($notice->id);
    app(GivingReceipts::class)->deliver($notice->id);
    Mail::assertSent(GivingReceipt::class, 1);
    expect($notice->fresh()->state)->toBe('sent')->and($tenant->usage()->count())->toBe(0);
    $batch = app(GivingCash::class)->record($fund, $user, ['source' => 'bulk_cash', 'amount' => 5000, 'currency' => 'GHS'], (string) Str::uuid());
    $none = GivingReceiptNotice::withoutGlobalScopes()->where('gift_id', $batch->id)->sole();
    expect($none->destination)->toBeNull()->and($none->state)->toBe('no_address');
    app(GivingReceipts::class)->deliver($none->id);
    Mail::assertSent(GivingReceipt::class, 1);
});

test('a failed receipt remains retryable without another charge or gift', function () {
    Queue::fake();
    [, $user,$fund] = GivingScenario::make();
    $gift = app(GivingCash::class)->record($fund, $user, ['source' => 'recorded_cash', 'amount' => 5000, 'currency' => 'GHS', 'payer_email' => 'giver@example.test'], (string) Str::uuid());
    $notice = GivingReceiptNotice::withoutGlobalScopes()->where('gift_id', $gift->id)->sole();
    Mail::shouldReceive('to')->once()->with('giver@example.test')->andThrow(new RuntimeException('Transport unavailable'));
    expect(fn () => app(GivingReceipts::class)->deliver($notice->id))->toThrow(RuntimeException::class);
    expect($notice->fresh()->state)->toBe('retryable')->and(App\Models\GivingGift::withoutGlobalScopes()->count())->toBe(1);
});

test('contactless online payments retain a receipt without mailing the gateway placeholder', function () {
    Queue::fake();
    Mail::fake();
    [$tenant,,,$attempt] = GivingScenario::online();
    $attempt->gift->update(['payer_email' => null, 'payer_email_hash' => null]);
    app(GivingPaymentConfirmer::class)->confirm($attempt->reference, GivingScenario::verified($attempt));
    $notice = GivingReceiptNotice::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();
    expect($notice->state)->toBe('no_address')->and($notice->destination)->toBeNull();
    Queue::assertNothingPushed();
    Mail::assertNothingSent();
});

test('receipt mail renders frozen details and current adjustments stay outside its original snapshot', function () {
    Queue::fake();
    [$tenant,$user,$fund] = GivingScenario::make();
    $gift = app(GivingCash::class)->record($fund, $user, ['source' => 'recorded_cash', 'amount' => 5000, 'currency' => 'GHS', 'payer_email' => 'giver@example.test'], (string) Str::uuid());
    $notice = GivingReceiptNotice::withoutGlobalScopes()->where('gift_id', $gift->id)->sole();
    $original = $notice->snapshot;
    $fund->update(['name' => 'Later name']);
    $tenant->update(['name' => 'Later organisation']);
    app(GivingCash::class)->correct($gift, $user, 1000, 'Correction', (string) Str::uuid());
    expect($notice->fresh()->snapshot)->toBe($original);
    $html = (new GivingReceipt($notice))->render();
    expect($html)->toContain($original['fund'], 'GHS 50.00')->not->toContain('Later name', 'Later organisation');
});
