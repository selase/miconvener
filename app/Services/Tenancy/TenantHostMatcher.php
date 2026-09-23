<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Tenant;

final class TenantHostMatcher
{
    public function matches(Tenant $tenant, string $host): bool
    {
        $baseDomain = $this->normalize((string) config('session.domain'));

        if ($baseDomain === '') {
            return false;
        }

        $expectedHost = $this->normalize("{$tenant->slug}.{$baseDomain}");

        return $this->normalize($host) === $expectedHost;
    }

    private function normalize(string $host): string
    {
        return mb_strtolower(mb_trim($host, " \n\r\t\v\0."));
    }
}
