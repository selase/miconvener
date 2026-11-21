<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OrganisationInvitation;
use App\Models\Tenant;
use App\Services\Tenancy\OrganisationInvitations;
use Illuminate\Console\Command;

final class MaintainOrganisationInvitations extends Command
{
    protected $signature = 'organisations:maintain-invitations {tenant : Organisation UUID} {--apply : Apply expiry, cleanup and untouched delivery recovery} {--invitation= : Invitation UUID for externally confirmed delivery reconciliation} {--outcome= : Confirmed provider outcome: sent or refused}';

    protected $description = 'Inspect invitation maintenance for one organisation; ambiguous deliveries are never replayed';

    public function handle(OrganisationInvitations $invitations): int
    {
        $tenant = Tenant::query()->findOrFail($this->argument('tenant'));
        if (! $this->option('apply')) {
            $pending = OrganisationInvitation::query()->where('tenant_id', $tenant->id)->where('status', 'pending')->count();
            $this->info("Dry run: {$pending} pending invitation(s). Use --apply for bounded maintenance.");

            return self::SUCCESS;
        }
        if ($this->option('invitation') || $this->option('outcome')) {
            if (! $this->option('invitation') || ! in_array($this->option('outcome'), ['sent', 'refused'], true)) {
                $this->error('Provide both an invitation UUID and a confirmed sent or refused outcome.');

                return self::INVALID;
            }
            $invitations->reconcileDelivery($tenant, (string) $this->option('invitation'), (string) $this->option('outcome'));
            $this->info('Confirmed delivery outcome recorded. No mail was replayed.');

            return self::SUCCESS;
        }
        if ($invitations->maintain($tenant)) {
            \App\Jobs\Tenancy\MaintainOrganisationInvitations::dispatch((string) $tenant->id)->afterCommit();
        }
        $this->info('Invitation maintenance applied. Uncertain sends require provider reconciliation.');

        return self::SUCCESS;
    }
}
