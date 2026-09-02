<?php

declare(strict_types=1);

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Spatie\Health\Commands\RunHealthChecksCommand;

final class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();
        $schedule->command(RunHealthChecksCommand::class)->everyMinute();
        $schedule->command('backup:clean')->daily()->at('01:00');
        $schedule->command('backup:run')->daily()->at('01:30');
        $schedule->command('backup:monitor')->dailyAt('02:00');

        // Usage Metering & Billing
        $schedule->command('tenants:audit-storage')->dailyAt('03:00');
        $schedule->command('tenants:audit-db')->dailyAt('03:30');
        $schedule->command('usage:process-rollups --period=hour')->hourly();
        $schedule->command('usage:process-rollups --period=day')->daily();
        $schedule->command('usage:prune')->dailyAt('04:00');
        $schedule->command('usage:check-alerts')->dailyAt('09:00'); // Check limits daily

        // Invoicing
        $schedule->command('billing:generate-invoices')->monthlyOn(1, '05:00');

        // Compliance
        $schedule->command('compliance:purge-expired')->dailyAt('05:30');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
