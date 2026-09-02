<?php

declare(strict_types=1);

use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('belongs to a tenant', function () {
    $tenant = Tenant::factory()->create();
    // Needs SubscriptionFactory? Or just create
    $subscription = Subscription::create([
        'tenant_id' => $tenant->id,
        'name' => 'default',
        'provider_id' => 'sub_123',
        'provider_status' => 'active',
    ]);

    expect($subscription->tenant)->toBeInstanceOf(Tenant::class);
    expect($subscription->tenant->id)->toBe($tenant->id);
});

it('casts dates', function () {
    $subscription = new Subscription(['current_period_end' => '2026-01-01 12:00:00']);
    expect($subscription->current_period_end)->toBeInstanceOf(Carbon\CarbonInterface::class);
});
