<?php

declare(strict_types=1);

namespace App\Console\Commands\Community;

use App\Services\Community\InboxRetention;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class PruneInboxCommand extends Command
{
    protected $signature = 'community:inbox-prune';

    protected $description = 'Prune private inbox items older than 24 months and their read receipts.';

    public function handle(InboxRetention $retention): int
    {
        $this->info('Pruned '.$retention->prune(CarbonImmutable::now()).' inbox items.');

        return self::SUCCESS;
    }
}
