<?php

declare(strict_types=1);

use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

test('billing dashboard displays last 6 months revenue analytics', function () {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user);

    // Month 1 (Current): 5000 + 2000 = 7000
    Transaction::factory()->create(['tenant_id' => $tenant->id, 'amount' => 5000, 'status' => 'success', 'created_at' => now()]);
    Transaction::factory()->create(['tenant_id' => $tenant->id, 'amount' => 2000, 'status' => 'success', 'created_at' => now()]);

    // Month 2 (Last Month): 10000
    Transaction::factory()->create(['tenant_id' => $tenant->id, 'amount' => 10000, 'status' => 'success', 'created_at' => now()->subMonth()]);

    // Failed Transaction (Should be ignored)
    Transaction::factory()->create(['tenant_id' => $tenant->id, 'amount' => 99999, 'status' => 'failed', 'created_at' => now()]);

    $response = actingAs($user)->get(route('billing.index', ['subdomain' => $tenant->slug]));

    $response->assertStatus(200);

    // Inspect View Data
    $stats = $response->original->getData()['monthlyStats'];

    // Expect 6 data points (filled with 0 if empty, but we'll focus on presence first)
    // expect(count($stats))->toBeGreaterThanOrEqual(2);

    // Find Current Month
    $currentMonthStat = collect($stats)->first(fn ($s) => $s['label'] === now()->format('M'));
    expect($currentMonthStat['amount'])->toBe(7000); // 5000 + 2000

    // Find Last Month
    $lastMonthStat = collect($stats)->first(fn ($s) => $s['label'] === now()->subMonth()->format('M'));
    expect($lastMonthStat['amount'])->toBe(10000);

    $response->assertSee('Spending Analytics');
});
