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
        // Deliberately not every minute. The environment scales to zero and the
        // scheduler wakes it, so a per-minute task holds it awake continuously and
        // the scale-to-zero setting buys nothing. Aligned with payouts:reconcile so
        // both run in the same wake window rather than each causing its own.
        // The heartbeat runs on the same fifteen-minute wake as the checks, so it
        // proves the scheduler is alive without waking the environment more often.
        $schedule->command('health:schedule-check-heartbeat')->everyFifteenMinutes();
        $schedule->command(RunHealthChecksCommand::class)->everyFifteenMinutes();
        $schedule->command('backup:clean')->daily()->at('01:00');
        $schedule->command('backup:run')->daily()->at('01:30');
        $schedule->command('backup:monitor')->dailyAt('02:00');

        // Usage Metering & Billing
        $schedule->command('tenants:audit-storage')->dailyAt('03:00');
        $schedule->command('tenants:audit-db')->dailyAt('03:30');
        /*
         * Plan gates read the tenant's own feature rows and treat a missing row
         * as permitted, so that a tenant never loses a capability to a sync
         * that has not run. The cost is that a newly added feature key is free
         * for everyone until it has. This closes that window nightly instead of
         * leaving it to whoever remembers.
         */
        $schedule->command('tenants:sync-features')->dailyAt('03:45')->withoutOverlapping();
        $schedule->command('usage:process-rollups --period=hour')->hourly();
        $schedule->command('usage:process-rollups --period=day')->daily();
        $schedule->command('usage:prune')->dailyAt('04:00');
        $schedule->command('usage:check-alerts')->dailyAt('09:00'); // Check limits daily
        $schedule->command('tenants:reset-usage --feature=event_registrations')->monthlyOn(1, '00:05');
        $schedule->command('tenants:reset-usage --feature=email_credits')->monthlyOn(1, '00:10');

        // Invoicing
        $schedule->command('billing:generate-invoices')->monthlyOn(1, '05:00');
        $schedule->command('billing:process-renewals')->dailyAt('07:00')->withoutOverlapping();
        $schedule->command('billing:daily-summary')->dailyAt('07:30');

        // Settlement — chase payouts whose transfer webhook never arrived, so a
        // lost webhook cannot strand money that has already left the platform.
        $schedule->command('payouts:reconcile')->everyFifteenMinutes()->withoutOverlapping();

        // Conference reminders. Shares the fifteen-minute wake window with the
        // health checks and payout reconciliation rather than causing its own,
        // and is fine-grained enough for rules whose offset is set in minutes.
        // EventNotificationRule::isDue() is what keeps a missed run recoverable
        // and a finished event's rule quiet.
        $schedule->command('app:dispatch-automated-notifications')
            ->everyFifteenMinutes()
            ->withoutOverlapping();

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
