<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TenantSendingDomain;
use App\Services\Mail\SendingDomainService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Asks SES about every organiser sending domain, so a pending one switches on
 * once its DNS records are found and a verified one switches off if they are
 * removed. One failed lookup is logged and skipped; the next run tries again.
 */
final class CheckSendingDomainsCommand extends Command
{
    protected $signature = 'mail:check-sending-domains';

    protected $description = 'Refresh the SES verification status of organiser sending domains';

    public function handle(SendingDomainService $domains): int
    {
        TenantSendingDomain::query()->each(function (TenantSendingDomain $sendingDomain) use ($domains): void {
            try {
                $domains->refresh($sendingDomain);
            } catch (Throwable $e) {
                report($e);
            }
        });

        return self::SUCCESS;
    }
}
