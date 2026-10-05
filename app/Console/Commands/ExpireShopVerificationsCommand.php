<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Shop;
use Illuminate\Console\Command;

/**
 * A verification is paid for a year (GHS 150). A year after it was granted,
 * the verified tick comes off until the business pays for a new review, so
 * the tick always means "checked within the last year".
 */
final class ExpireShopVerificationsCommand extends Command
{
    protected $signature = 'marketplace:expire-verifications';

    protected $description = 'Remove the verified tick from businesses verified more than a year ago';

    public function handle(): int
    {
        $expired = Shop::query()
            ->where('verification_status', Shop::VERIFICATION_VERIFIED)
            ->whereNotNull('verified_at')
            ->where('verified_at', '<', now()->subYear())
            ->update(['verification_status' => Shop::VERIFICATION_UNVERIFIED]);

        $this->info("{$expired} verification(s) expired.");

        return self::SUCCESS;
    }
}
