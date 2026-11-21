<?php

declare(strict_types=1);

namespace App\Jobs\Tenancy;

use App\Jobs\Middleware\TenantAwareJob;
use App\Models\OrganisationInvitation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DispatchOrganisationInvitationMaintenance implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 3;

    public ?string $tenantId = null;

    public function __construct(public readonly ?string $afterTenantId = null) {}

    /** @return list<TenantAwareJob> */
    public function middleware(): array
    {
        return [app(TenantAwareJob::class)];
    }

    public function handle(): void
    {
        $ids = OrganisationInvitation::query()->select('tenant_id')->distinct()->orderBy('tenant_id')
            ->when($this->afterTenantId, fn ($query) => $query->where('tenant_id', '>', $this->afterTenantId))->limit(100)->pluck('tenant_id');
        foreach ($ids as $tenantId) {
            MaintainOrganisationInvitations::dispatch((string) $tenantId)->afterCommit();
        }
        if ($ids->count() === 100) {
            self::dispatch((string) $ids->last())->afterCommit();
        }
    }
}
