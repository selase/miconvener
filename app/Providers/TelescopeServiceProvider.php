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

        Telescope::filterBatch(fn (\Illuminate\Support\Collection $entries): bool => ! request()->is('organisation-invitations', 'organisation-invitations/*', 'account-identity', 'account-identity/*'));

        Telescope::filter(function (IncomingEntry $entry): bool {
            $uri = (string) parse_url((string) ($entry->content['uri'] ?? ''), PHP_URL_PATH);
            $identityPaths = ['organisation-invitations', 'organisation-invitations/*', 'account-identity', 'account-identity/*'];
            if (request()->is(...$identityPaths) || \Illuminate\Support\Str::is($identityPaths, mb_ltrim($uri, '/'))) {
                return false;
            }
            if (request()->is('marketplace/shops/*/quotes', 'marketplace/checkout/*/callback', 'store/fulfilment-policies', 'store/collections', 'store/collections/*', 'store/orders', 'store/orders/*', 'store/finance', 'store/refunds/*', 'store/payouts', 'store/payouts/*', 'webhooks/paystack', 'events/*/badges/library*', 'events/*/data/badges', 'events/*/badges/template/artwork', 'events/*/badges/clean-preview', 'admin/badge-template-sales*')) {
                return false;
            }
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
        Telescope::hideRequestParameters(['password', 'password_confirmation', 'token', 'organisation_invitation', 'organisation_invitation_mfa_secret', 'staff_account_security', '_token', 'otp', 'data.authorization', 'data.authorization_code', 'code', 'secret', 'capability', 'community_verified_phone',
            'community_member_grants', 'community_claim_capabilities', 'community_join_capabilities', 'attendee_verified_platform',
            'attendee_checkout_grants', 'attendee_registered_in_session']);
        Telescope::hideRequestHeaders(['cookie', 'x-csrf-token', 'x-xsrf-token']);
    }
}
