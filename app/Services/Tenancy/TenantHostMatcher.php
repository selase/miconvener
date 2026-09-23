<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Support\TenantHandle;

final class TenantHostMatcher
{
    public function matches(Tenant $tenant, string $host): bool
    {
        return $this->tenantSlug($host) === mb_strtolower($tenant->slug);
    }

    public function isPlatformHost(string $host): bool
    {
        $baseDomain = $this->baseDomain();

        if ($baseDomain === '') {
            return false;
        }

        return $this->normalizeHost($host) === $baseDomain;
    }

    public function isWwwHost(string $host): bool
    {
        $baseDomain = $this->baseDomain();

        if ($baseDomain === '') {
            return false;
        }

        return $this->normalizeHost($host) === "www.{$baseDomain}";
    }

    public function tenantSlug(string $host): ?string
    {
        $baseDomain = $this->baseDomain();

        if ($baseDomain === '') {
            return null;
        }

        $host = $this->normalizeHost($host);
        $suffix = ".{$baseDomain}";

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $slug = mb_substr($host, 0, -mb_strlen($suffix));

        if ($slug === '' || str_contains($slug, '.') || in_array($slug, TenantHandle::RESERVED, true)) {
            return null;
        }

        return $slug;
    }

    public function normalizeHost(string $host): string
    {
        $host = mb_strtolower(mb_trim($host));

        return str_ends_with($host, '.') ? mb_substr($host, 0, -1) : $host;
    }

    public function baseDomain(): string
    {
        return mb_strtolower(mb_trim((string) config('session.domain'), " \n\r\t\v\0."));
    }
}
