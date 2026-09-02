<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class); // Wait, Pest.php adds RefreshDatabase? Yes.
// So I can remove uses() entirely if Pest.php covers it.
// uses(TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class)->in('Feature', 'Unit');
// So I should remove the uses() line completely.

it('belongs to a tenant', function () {
    $tenant = Tenant::factory()->create();
    $transaction = Transaction::factory()->create(['tenant_id' => $tenant->id]);

    expect($transaction->tenant)->toBeInstanceOf(Tenant::class);
    expect($transaction->tenant->id)->toBe($tenant->id);
});

it('casts meta to array', function () {
    $transaction = Transaction::factory()->create(['meta' => ['foo' => 'bar']]);

    expect($transaction->meta)->toBeArray();
    expect($transaction->meta['foo'])->toBe('bar');
});

it('can filter by successful status', function () {
    Transaction::factory()->create(['status' => 'success']);
    Transaction::factory()->create(['status' => 'failed']);

    expect(Transaction::successful()->count())->toBe(1);
});
