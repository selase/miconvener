<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Marketplace\VenueQuoteNotices;
use Illuminate\Console\Command;

final class DeliverVenueQuoteNoticesCommand extends Command
{
    protected $signature = 'venue:deliver-quote-notices';

    protected $description = 'Retry buyer quote emails whose last attempt failed and recover sends that never reported back.';

    public function handle(VenueQuoteNotices $notices): int
    {
        $this->info('Queued '.$notices->retryDue().' quote notice(s) for another attempt.');

        return self::SUCCESS;
    }
}
