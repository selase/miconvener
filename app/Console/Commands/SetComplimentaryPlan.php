<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class SetComplimentaryPlan extends Command
{
    protected $signature = 'billing:complimentary {tenant : The tenant slug or id} {--off : Start billing the tenant for their plan}';

    protected $description = 'Mark a tenant\'s paid plan as complimentary (never renewed or ended), or end that';

    public function handle(): int
    {
        $key = (string) $this->argument('tenant');
        $tenant = Tenant::query()
            ->where('slug', $key)
            ->when(Str::isUuid($key), fn ($query) => $query->orWhere('id', $key))
            ->first();

        if (! $tenant) {
            $this->error("No tenant found for '{$key}'.");

            return self::FAILURE;
        }

        $complimentary = ! $this->option('off');
        $tenant->forceFill(['billing_complimentary' => $complimentary])->save();

        $this->info($complimentary
            ? "{$tenant->name} is now complimentary: their plan will not be renewed or ended."
            : "{$tenant->name} is billed again: their plan renews from its current period, or they can choose a plan.");

        return self::SUCCESS;
    }
}
