<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Checks\SuperadminNotifiable;
use App\Mail\Billing\BillingDailySummaryMail;
use App\Services\Billing\BillingDailySummary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

final class SendBillingDailySummary extends Command
{
    protected $signature = 'billing:daily-summary {--force : Send even when nothing happened}';

    protected $description = 'Email superadmins what billing did in the last day and what needs attention';

    public function handle(BillingDailySummary $summaries): int
    {
        $summary = $summaries->build();

        if (! $this->option('force') && ! $summaries->isNoteworthy($summary)) {
            $this->info('Nothing to report.');

            return self::SUCCESS;
        }

        $recipients = SuperadminNotifiable::superadminEmails();

        if ($recipients === []) {
            $this->warn('No superadmin to send the billing summary to.');

            return self::SUCCESS;
        }

        Mail::to($recipients)->send(new BillingDailySummaryMail($summary));
        $this->info('Billing summary sent to '.count($recipients).' superadmin(s).');

        return self::SUCCESS;
    }
}
