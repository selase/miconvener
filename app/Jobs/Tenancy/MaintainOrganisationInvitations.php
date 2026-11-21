<?php

declare(strict_types=1);

namespace App\Jobs\Tenancy;

use App\Jobs\Middleware\TenantAwareJob;
use App\Models\Tenant;
use App\Services\Tenancy\OrganisationInvitations;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class MaintainOrganisationInvitations implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 3;

    public function __construct(public readonly string $tenantId) {}

    /** @return list<TenantAwareJob> */
    public function middleware(): array
    {
        return [app(TenantAwareJob::class)];
    }

    public function handle(OrganisationInvitations $invitations): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant && $invitations->maintain($tenant)) {
            self::dispatch($this->tenantId)->afterCommit();
        }
    }
}
