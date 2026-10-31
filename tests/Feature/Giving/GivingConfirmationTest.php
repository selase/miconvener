<?php

declare(strict_types=1);

use App\Models\GivingGift;
use App\Models\LedgerTransaction;
use App\Services\Giving\GivingPaymentConfirmer;
use Tests\Support\GivingScenario;

test('verified confirmation uses frozen commission survives close or flag changes and is idempotent', function () {
    [$tenant, , $fund, $attempt] = GivingScenario::online();
    $fund->update(['state' => 'closed']);
    $tenant->features()->where('feature_key', 'community_giving')->update(['enabled' => false]);
    $tenant->update(['settlement_mode' => 'own_gateway']);
    App\Models\CommercialTermsOverride::query()->create(['tenant_id' => $tenant->id, 'stream' => 'giving', 'rate' => 8, 'reason' => 'Changed after start']);
    $data = GivingScenario::verified($attempt);
    $data['metadata'] = json_encode($data['metadata']);
    $confirmed = app(GivingPaymentConfirmer::class)->confirm($attempt->reference, $data);
    expect($confirmed?->state)->toBe('confirmed')->and((float) $confirmed->rate)->toBe(1.0)->and($confirmed->commission_amount)->toBe(100)
        ->and($attempt->gift->fresh()->status)->toBe('completed');
    $before = LedgerTransaction::query()->count();
    app(GivingPaymentConfirmer::class)->confirm($attempt->reference, $data);
    expect(LedgerTransaction::query()->count())->toBe($before)->and(GivingGift::query()->count())->toBe(1);
});

test('mismatched verified data is quarantined without money or completed gifts', function (array $overrides) {
    [, , , $attempt] = GivingScenario::online();
    expect(app(GivingPaymentConfirmer::class)->confirm($attempt->reference, GivingScenario::verified($attempt, $overrides)))->toBeNull()
        ->and($attempt->fresh()->state)->toBe('needs_review')
        ->and($attempt->gift->fresh()->status)->toBe('pending')
        ->and(LedgerTransaction::query()->count())->toBe(0);
})->with([
    'amount' => [['amount' => 9999]], 'currency' => [['currency' => 'USD']], 'provider' => [['provider' => 'stripe']],
    'account' => [['account_identity' => 'another-account']], 'reference' => [['reference' => 'some-other-reference']],
    'source' => [['metadata' => ['source_id' => 'unrelated-gift']]], 'fund' => [['metadata' => ['fund_id' => 'foreign-fund']]],
    'tenant' => [['metadata' => ['tenant_id' => 'foreign-tenant']]], 'fee-exhausted' => [['fees' => 10000]],
]);

test('unknown references and failed payment results cannot be fulfilled', function () {
    [, , , $attempt] = GivingScenario::online();
    $confirmer = app(GivingPaymentConfirmer::class);
    expect($confirmer->confirm('unknown', GivingScenario::verified($attempt)))->toBeNull()
        ->and($confirmer->confirm($attempt->reference, GivingScenario::verified($attempt, ['status' => 'failed'])))->toBeNull()
        ->and($attempt->gift->fresh()->status)->toBe('pending');
});

test('checkout initialization cannot downgrade a payment confirmed by an early webhook', function () {
    [$tenant, , $fund] = GivingScenario::online();
    $level = Illuminate\Support\Facades\DB::connection('landlord')->transactionLevel();
    $key = (string) Illuminate\Support\Str::uuid();
    Illuminate\Support\Facades\Http::fake([
        '*api.paystack.co/customer*' => Illuminate\Support\Facades\Http::response(['status' => true, 'data' => ['customer_code' => 'CUS_GIVING', 'email' => 'giver@example.test']]),
        '*api.paystack.co/transaction/initialize' => function ($request) use ($key, $level) {
            expect(Illuminate\Support\Facades\DB::connection('landlord')->transactionLevel())->toBe($level);
            $attempt = App\Models\GivingPaymentAttempt::withoutGlobalScopes()->where('submission_key', $key)->firstOrFail();
            $data = GivingScenario::verified($attempt);
            $data['metadata'] = $request['metadata'];
            app(GivingPaymentConfirmer::class)->confirm($attempt->reference, $data);

            return Illuminate\Support\Facades\Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/giving-test']]);
        },
    ]);
    app(App\Services\Giving\GivingCheckout::class)->start($fund, ['amount_minor' => 10000, 'currency' => 'GHS', 'provider' => 'paystack'], app('session.store'), $key);
    expect(App\Models\GivingPaymentAttempt::withoutGlobalScopes()->where('submission_key', $key)->firstOrFail()->state)->toBe('confirmed');
});

test('a stale review request never downgrades an already confirmed payment', function () {
    [, , , $attempt] = GivingScenario::online();
    $confirmer = app(GivingPaymentConfirmer::class);
    $confirmer->confirm($attempt->reference, GivingScenario::verified($attempt));
    $confirmer->quarantine($attempt, 'late_verification_unavailable');
    expect($attempt->fresh()->state)->toBe('confirmed')->and($attempt->fresh()->review_reason)->toBeNull();
});
