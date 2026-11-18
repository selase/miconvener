<?php

declare(strict_types=1);

namespace Tests\Infrastructure;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Cache;
use Tests\CreatesApplication;

final class PublicMailRateLimitTest extends TestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'session.driver' => 'array', 'session.block' => false]);
        Cache::flush();
        $this->withoutMiddleware(\App\Http\Middleware\ResolveTenant::class);
    }

    public function test_invalid_signup_requests_are_limited_before_validation(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/register', ['email' => 'invalid-'.$i])->assertUnprocessable();
        }
        $this->postJson('/register', ['email' => 'another-invalid'])->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_signup_recipient_budget_is_shared_across_ips(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$i])->postJson('/register', ['email' => $i % 2 ? 'INVALID' : 'invalid'])->assertUnprocessable();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.4'])->postJson('/register', ['email' => 'Invalid'])->assertStatus(429);
    }

    public function test_password_reset_source_budget_limits_rotating_recipients(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/forgot-password', ['email' => 'invalid-'.$i])->assertUnprocessable();
        }
        $this->postJson('/forgot-password', ['email' => 'another-invalid'])->assertStatus(429);
    }

    public function test_recipient_limit_survives_case_changes_and_ip_rotation(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$i])->postJson('/forgot-password', ['email' => $i % 2 ? 'INVALID' : 'invalid'])->assertUnprocessable();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.4'])->postJson('/forgot-password', ['email' => 'Invalid'])->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_application_budget_limits_distributed_signup_requests(): void
    {
        for ($i = 1; $i <= 100; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$i])->postJson('/register', ['email' => 'invalid-'.$i])->assertUnprocessable();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.101'])->postJson('/register', ['email' => 'invalid-101'])->assertStatus(429);
    }

    public function test_non_string_email_is_counted_without_server_error(): void
    {
        $this->postJson('/register', ['email' => ['invalid']])->assertUnprocessable();
    }

    public function test_api_and_browser_share_signup_budget(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson($i % 2 ? '/api/v1/auth/register' : '/register', ['email' => 'invalid-'.$i])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/register', ['email' => 'invalid-six'])->assertStatus(429);
    }
}
