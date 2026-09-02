<?php

declare(strict_types=1);

use App\Jobs\Middleware\TenantAwareJob;
use App\Traits\TenantAware;

test('trait adds tenant id property', function () {
    $job = new class
    {
        use TenantAware;
    };

    expect(property_exists($job, 'tenantId'))->toBeTrue();
});

test('trait adds middleware', function () {
    $job = new class
    {
        use TenantAware;
    };

    $middleware = $job->middleware();
    expect($middleware)->toBeArray();
    expect($middleware[0])->toBeInstanceOf(TenantAwareJob::class);
});
