<?php

declare(strict_types=1);

namespace App\Checks;

use App\Models\Tenant;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

final class TenantCustomDomainCheck extends Check
{
    /**
     * Run the check.
     */
    public function run(): Result
    {
        $tenantsWithCustomDomains = Tenant::whereNotNull('custom_domain')
            ->where('custom_domain_status', 'active')
            ->get();

        if ($tenantsWithCustomDomains->isEmpty()) {
            return Result::make('No custom domains configured')->ok();
        }

        $failedDomains = [];

        foreach ($tenantsWithCustomDomains as $tenant) {
            if (! $this->isDomainResolvable($tenant->custom_domain)) {
                $failedDomains[] = $tenant->custom_domain." (Tenant: {$tenant->name})";
            }
        }

        if (count($failedDomains) > 0) {
            return Result::make()
                ->failed('Connectivity issues with custom domains')
                ->shortSummary(count($failedDomains).' domains unreachable')
                ->meta(['failed_domains' => $failedDomains]);
        }

        return Result::make('All custom domains are resolvable')->ok();
    }

    /**
     * Check if a domain is resolvable via DNS.
     */
    private function isDomainResolvable(string $domain): bool
    {
        // Simple DNS check
        return checkdnsrr($domain, 'ANY');
    }
}
