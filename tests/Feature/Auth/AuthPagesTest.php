<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

/**
 * The auth screens are the first thing someone sees after clicking through from
 * the marketing site, so they were selling the starter kit this application was
 * built from -- "Build SaaS, not boilerplate" -- to a visitor who came to run an
 * event. One of its four claims was also no longer true: signups stopped
 * provisioning a database per tenant when they moved to shared isolation.
 */
test('the sign-in page renders and speaks to an organizer', function (): void {
    $response = $this->get('/login');

    $response->assertOk();

    foreach (['Build SaaS', 'boilerplate', 'tenant databases', 'SSO, 2FA', 'audit logs'] as $stale) {
        $response->assertDontSee($stale, false);
    }
});

test('the registration page renders and speaks to an organizer', function (): void {
    $response = $this->get('/register');

    $response->assertOk();
    $response->assertSee('Run the whole event', false);

    foreach (['Build SaaS', 'boilerplate', 'tenant databases', 'Stripe and Paystack'] as $stale) {
        $response->assertDontSee($stale, false);
    }
});
