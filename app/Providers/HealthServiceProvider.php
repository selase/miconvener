<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Spatie\CpuLoadHealthCheck\CpuLoadCheck;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\EnvironmentCheck;
use Spatie\Health\Checks\Checks\OptimizedAppCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;
use Spatie\SecurityAdvisoriesHealthCheck\SecurityAdvisoriesCheck;

final class HealthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // spatie health check
        Health::checks([
            /**
             * This check will monitor the percentage of available disk space.
             * By default, this check will send:
             * a warning when the used disk space is above 70%
             * a failure when the used disk space is above 90%
             */
            UsedDiskSpaceCheck::new(),

            /**
             * This check makes sure your application can connect to a database.
             * If the default database connection does not work, this check will fail.
             */
            DatabaseCheck::new(),

            CpuLoadCheck::new()
                ->failWhenLoadIsHigherInTheLast5Minutes(2.0)
                ->failWhenLoadIsHigherInTheLast15Minutes(1.5),

            /**
             * This check will make sure that debug mode is set to false. It will fail when debug mode is true.
             */
            DebugModeCheck::new()
                ->unless(app()->isLocal()),

            /**
             * This check will make sure your application is running used the right environment.
             * By default, this check will fail when the environment is not equal to production.
             */
            EnvironmentCheck::new()
                ->expectEnvironment(app()->environment()),

            /**
             * This check will make sure the schedule is running.
             * If the check detects that the schedule is not run every minute, it will fail.
             * This check relies on cache.
             */
            ScheduleCheck::new(),

            /**
             * This check will check if the PHP packages installed in your project have known security vulnerabilities.
             */
            SecurityAdvisoriesCheck::new(),

            /**
             * This check makes sure the application can connect to your cache system and read/write to the cache keys.
             * By default, this check will make sure the default connection is working.
             */
            CacheCheck::new(),

            /**
             * To improve performance, Laravel can cache configuration files, routes and events.
             * Using the OptimizedAppCheck you can make sure these things are actually cached.
             */
            OptimizedAppCheck::new()
                ->unless(app()->isLocal()),

            \App\Checks\TenantCustomDomainCheck::new(),
        ]);
    }
}
