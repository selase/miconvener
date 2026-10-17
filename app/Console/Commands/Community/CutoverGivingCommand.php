<?php

declare(strict_types=1);

namespace App\Console\Commands\Community;

use App\Models\Tenant;
use App\Services\Giving\GivingContributionBridge;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class CutoverGivingCommand extends Command
{
    protected $signature = 'giving:cutover {tenant} {--at=} {--apply} {--off : Explicitly restore future starts to the legacy route}';

    protected $description = 'Preview a UTC event contribution cutover; existing sources retain their category.';

    public function handle(GivingContributionBridge $bridge): int
    {
        $tenant = Tenant::query()->where('slug', $this->argument('tenant'))->first();
        if (! $tenant || (! $this->option('off') && ! $this->option('at')) || ($this->option('off') && $this->option('at'))) {
            $this->error('Choose an organisation and a UTC --at boundary, or --off.');

            return self::FAILURE;
        }
        if (! $this->option('off') && ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', (string) $this->option('at'))) {
            $this->error('Use an explicit UTC timestamp ending in Z.');

            return self::FAILURE;
        }
        try {
            $at = $this->option('off') ? null : CarbonImmutable::parse($this->option('at'), 'UTC');
        } catch (Throwable) {
            $this->error('Invalid UTC boundary.');

            return self::FAILURE;
        }
        if ($at !== null && $at->format('Y-m-d\TH:i:s\Z') !== $this->option('at')) {
            $this->error('Invalid UTC calendar date.');

            return self::FAILURE;
        }
        if ($this->option('apply')) {
            if (! $this->option('off')) {
                $report = $bridge->reconcile($tenant);
                if ($report['discrepancies'] !== []) {
                    $this->error('Reconciliation discrepancies must be resolved before cutover.');
                    $this->line(json_encode($report, JSON_THROW_ON_ERROR));

                    return self::FAILURE;
                }
            }
            $bridge->cutover($tenant, $at);
        }
        $this->line(json_encode(['tenant' => $tenant->slug, 'boundary' => $at?->toIso8601String(), 'applied' => (bool) $this->option('apply'), 'existing_sources' => 'unchanged'], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
