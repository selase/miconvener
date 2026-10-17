<?php

declare(strict_types=1);
use App\Services\Giving\GivingContributionBridge;
use Tests\Support\GivingScenario;

test('reconciliation is read only detects review attempts and verifies paid Giving sources', function () {
    [$tenant,,,$attempt] = GivingScenario::paid();
    $bridge = app(GivingContributionBridge::class);
    $before = $attempt->fresh()->getRawOriginal();
    $report = $bridge->reconcile($tenant);
    expect($report['discrepancies'])->toBe([])->and($report['confirmed_attempts'])->toBe(1)->and($attempt->fresh()->getRawOriginal())->toBe($before);
    $attempt->update(['state' => 'needs_review']);
    expect($bridge->reconcile($tenant)['discrepancies'])->not->toBe([]);
    $this->artisan('giving:reconcile', ['--tenant' => $tenant->slug])->assertFailed();
});

test('an applied cutover refuses known reconciliation discrepancies and invalid UTC dates', function () {
    [$tenant,,,$attempt] = GivingScenario::paid();
    $attempt->update(['state' => 'needs_review']);
    $this->artisan('giving:cutover', ['tenant' => $tenant->slug, '--at' => '2026-10-18T12:00:00Z', '--apply' => true])->assertFailed();
    expect(App\Models\CommunitySettings::for($tenant)->giving_event_cutover_at)->toBeNull();
    $attempt->update(['state' => 'confirmed']);
    $this->artisan('giving:cutover', ['tenant' => $tenant->slug, '--at' => '2026-02-31T12:00:00Z', '--apply' => true])->assertFailed();
});
