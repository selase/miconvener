<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\LoginLogs;
use App\Listeners\LogoutLogs;
use App\Listeners\SendWelcomeEmail;
use App\Listeners\SetTenantIdInSession;
use App\Listeners\UpdateLastLoginIp;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

final class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
            SendWelcomeEmail::class,
        ],

        Login::class => [
            UpdateLastLoginIp::class,
            LoginLogs::class,
            SetTenantIdInSession::class,
        ],

        Logout::class => [
            LogoutLogs::class,
        ],
    ];

    /**
     * The subscriber classes to register.
     *
     * @var array
     */
    protected $subscribe = [
        \App\Listeners\WebhookEventSubscriber::class,
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    public function shouldDiscoverEvents()
    {
        return false;
    }
}
