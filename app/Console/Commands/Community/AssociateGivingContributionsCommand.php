<?php

declare(strict_types=1);

namespace App\Console\Commands\Community;

use App\Models\Tenant;
use App\Services\Giving\GivingContributionBridge;
use Illuminate\Console\Command;

final class AssociateGivingContributionsCommand extends Command
{
    protected $signature = 'giving:associate-contributions {--tenant=} {--apply} {--remove : Remove legacy association links only}';

    protected $description = 'Preview or apply bounded private event-fund associations without rewriting money.';

    public function handle(GivingContributionBridge $bridge): int
    {
        if (($this->option('apply') || $this->option('remove')) && ! $this->option('tenant')) {
            $this->error('Applying requires an explicit organisation.');

            return self::FAILURE;
        }
        if ($this->option('remove') && ! $this->option('apply')) {
            $this->error('Removing links requires --apply and an explicit organisation.');

            return self::FAILURE;
        }
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($q) => $q->where('slug', $this->option('tenant')))->get();
        if ($tenants->isEmpty()) {
            $this->error('Organisation not found.');

            return self::FAILURE;
        }
        foreach ($tenants as $tenant) {
            $result = $this->option('remove') ? ['removed' => $bridge->removeLegacyAssociations($tenant)] : $bridge->associate($tenant, (bool) $this->option('apply'));
            $this->line(json_encode(['tenant' => $tenant->slug, ...$result], JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
