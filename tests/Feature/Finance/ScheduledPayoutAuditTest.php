<?php

declare(strict_types=1);

use App\Services\Finance\PayoutFinancialAudit;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../Support/ScheduledPayoutScenario.php';

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    config(['services.settlement.paystack.secret_key' => 'sk_test_schedule']);
});

test('a dry audit does not certify or change financial history', function () {
    [$policy, , , $platform] = fundedScheduledPolicy();
    Http::fake(['api.paystack.co/balance' => Http::response(['status' => true, 'data' => [['currency' => 'GHS', 'balance' => 15000]]])]);
    $before = App\Models\PayoutReconciliationCheckpoint::count();
    $report = app(PayoutFinancialAudit::class)->inspect();
    expect($report['matched'])->toBeTrue()->and(App\Models\PayoutReconciliationCheckpoint::count())->toBe($before);
});

test('an unexplained provider cash discrepancy cannot refresh reconciliation', function () {
    fundedScheduledPolicy();
    Http::fake(['api.paystack.co/balance' => Http::response(['status' => true, 'data' => [['currency' => 'GHS', 'balance' => 15001]]])]);
    expect(app(PayoutFinancialAudit::class)->inspect()['matched'])->toBeFalse();
});

test('only a platform reviewer can certify matched source evidence', function () {
    [, , $owner, $platform] = fundedScheduledPolicy();
    Http::fake(['api.paystack.co/balance' => Http::response(['status' => true, 'data' => [['currency' => 'GHS', 'balance' => 15000]]])]);
    $audit = app(PayoutFinancialAudit::class);
    $report = $audit->inspect();
    expect(fn () => $audit->record($report, $owner, 'statement-001', 'Reviewed provider balance and all transfer evidence.'))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    $before = App\Models\PayoutReconciliationCheckpoint::where('scope_key', '*')->count();
    $audit->record($report, $platform, 'statement-001', 'Reviewed provider balance and all transfer evidence.');
    expect(App\Models\PayoutReconciliationCheckpoint::where('scope_key', '*')->count())->toBe($before + 1);
});
