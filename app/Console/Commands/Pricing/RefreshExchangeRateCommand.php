<?php

declare(strict_types=1);

namespace App\Console\Commands\Pricing;

use App\Services\Pricing\ExchangeRates;
use Illuminate\Console\Command;

final class RefreshExchangeRateCommand extends Command
{
    protected $signature = 'fx:refresh {--force : Ask the sources even if the cached rate is still fresh}';

    protected $description = 'Fetch and store the Bank of Ghana USD to GHS interbank rate used for USD pricing.';

    public function handle(ExchangeRates $rates): int
    {
        $rate = $rates->refresh((bool) $this->option('force'));

        if ($rate === null) {
            $this->error('No USD to GHS rate is available from any source, and none is stored.');

            return self::FAILURE;
        }

        $this->info(sprintf('USD/GHS %s for %s (%s, %d day(s) old).', $rate->rate, $rate->asOf->toDateString(), $rate->source, $rate->ageDays()));

        return self::SUCCESS;
    }
}
