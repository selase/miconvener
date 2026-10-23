<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

final class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telescope::night();

        $this->hideSensitiveRequestDetails();

        Telescope::filter(function (IncomingEntry $entry): bool {
            $uri = (string) parse_url((string) ($entry->content['uri'] ?? ''), PHP_URL_PATH);
            if (request()->is('my', 'my/*') || ($entry->isRequest() && \Illuminate\Support\Str::is(['my', 'my/*'], mb_ltrim($uri, '/')))) {
                return false;
            }
            if ($this->app->environment('local')) {
                return true;
            }
            if ($entry->isReportableException()) {
                return true;
            }
            if ($entry->isFailedRequest()) {
                return true;
            }
            if ($entry->isFailedJob()) {
                return true;
            }
            if ($entry->isScheduledTask()) {
                return true;
            }

            return $entry->hasMonitoredTag();
        });
    }

    /**
     * Register the Telescope gate.
     *
     * This gate determines who can access Telescope in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', fn ($user): bool => in_array($user->email, [
            //
        ]));
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    private function hideSensitiveRequestDetails(): void
    {
        Telescope::hideRequestParameters(['_token', 'otp', 'code', 'secret', 'capability', 'community_verified_phone',
            'community_member_grants', 'community_claim_capabilities', 'community_join_capabilities', 'attendee_verified_platform',
            'attendee_checkout_grants', 'attendee_registered_in_session']);
        Telescope::hideRequestHeaders(['cookie', 'x-csrf-token', 'x-xsrf-token']);
    }
}
