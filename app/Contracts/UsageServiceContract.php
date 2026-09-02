<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Tenant;

interface UsageServiceContract
{
    public function recordRequest(Tenant $tenant, string $route, int $status, string $statusBucket, int $durationMs): void;

    public function recordJob(Tenant $tenant, string $jobClass, bool $success, int $runtimeMs): void;

    public function recordActiveUser(Tenant $tenant, string $userId): void;
}
