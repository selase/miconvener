<?php

declare(strict_types=1);

use App\Checks\FailedJobsCheck;
use App\Checks\PaystackWebhookCheck;
use App\Checks\RenewalRunCheck;
use App\Checks\SuperadminNotifiable;
use App\Mail\Billing\BillingDailySummaryMail;
use App\Models\Package;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Operations\OperationalSignals;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Billing work happens out of sight — a daily run, queued emails, webhooks — so
 * when it stops, nothing looks broken. These alerts are how anyone finds out.
 */
beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);
    cache()->flush();
});

function platformSuperadmin(string $email = 'Owner@MiConvener.test'): User
{
    $user = User::factory()->create(['email' => $email]);
    setPermissionsTeamId(null);
    $user->assignRole(Role::query()->where('name', 'Superadmin')->whereNull('tenant_id')->firstOrFail());

    return $user;
}

test('the renewal run check warns before the first run, passes after a run and fails when a day is missed', function (): void {
    expect(RenewalRunCheck::new()->run()->status->value)->toBe('warning');

    $this->travelTo('2026-10-10 07:00:00');
    app(OperationalSignals::class)->recordRenewalRun(['acme: charged GHS 99.00 (attempt 1)']);

    $this->travelTo('2026-10-11 07:05:00');
    expect(RenewalRunCheck::new()->run()->status->value)->toBe('ok');

    $this->travelTo('2026-10-11 09:30:00');
    expect(RenewalRunCheck::new()->run())
        ->status->value->toBe('failed')
        ->getNotificationMessage()->toContain('26 hours ago');
});

test('a real renewal run is recorded; a pretend run is not', function (): void {
    $this->artisan('billing:process-renewals', ['--pretend' => true]);
    expect(app(OperationalSignals::class)->lastRenewalRun())->toBeNull();

    $this->artisan('billing:process-renewals');
    expect(app(OperationalSignals::class)->lastRenewalRun())->not->toBeNull();
});

test('a recently failed job fails the check once, then drops out of it', function (): void {
    expect(FailedJobsCheck::new()->run()->status->value)->toBe('ok');

    DB::connection('landlord')->table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Mail\\Billing\\PaymentReceiptMail']),
        'exception' => "Symfony\\Component\\Mailer\\Exception\\TransportException: Connection refused\n#0 stack", 'failed_at' => now(),
    ]);

    expect(FailedJobsCheck::new()->run())
        ->status->value->toBe('failed')
        ->getNotificationMessage()->toContain('PaymentReceiptMail')->toContain('Connection refused');

    $this->travel(31)->minutes();
    expect(FailedJobsCheck::new()->run()->status->value)->toBe('ok');
});

test('an unprocessed Paystack webhook fails the check, and so does a run of refused signatures', function (): void {
    $signals = app(OperationalSignals::class);

    $signals->recordWebhookFailure('signature', 'signature matched neither key');
    $signals->recordWebhookFailure('signature', 'signature matched neither key');
    expect(PaystackWebhookCheck::new()->run()->status->value)->toBe('ok');

    $signals->recordWebhookFailure('signature', 'signature matched neither key');
    expect(PaystackWebhookCheck::new()->run()->status->value)->toBe('failed');

    $this->travel(31)->minutes();
    $signals->recordWebhookFailure('processing', 'charge.success: SQLSTATE[23505]');
    expect(PaystackWebhookCheck::new()->run())
        ->status->value->toBe('failed')
        ->getNotificationMessage()->toContain('charge.success');
});

test('a webhook with a bad signature is recorded for the check', function (): void {
    config(['services.paystack.secret_key' => 'sk_billing', 'services.settlement.paystack.secret_key' => 'sk_settlement']);

    $this->call('POST', '/webhooks/paystack', [], [], [], ['HTTP_x-paystack-signature' => 'forged', 'CONTENT_TYPE' => 'application/json'], '{"event":"charge.success"}')
        ->assertStatus(400);

    expect(app(OperationalSignals::class)->webhookFailuresSince(now()->subMinute()->toImmutable(), 'signature'))->toHaveCount(1);
});

test('health alerts go to the application superadmins, not to tenant owners', function (): void {
    platformSuperadmin('Owner@MiConvener.test');
    $tenantOwner = User::factory()->create(['email' => 'org-owner@example.com']);
    $tenant = Tenant::factory()->create();
    setPermissionsTeamId($tenant->id);
    $tenantOwner->assignRole('Org Superadmin');

    expect((new SuperadminNotifiable)->routeNotificationForMail())->toBe(['owner@miconvener.test'])
        ->and(config('health.notifications.notifiable'))->toBe(SuperadminNotifiable::class);
});

test('the scheduler endpoint reports a stopped scheduler with 503 for an external monitor', function (): void {
    $this->getJson('/health/scheduler')->assertStatus(503)->assertJson(['status' => 'failing']);

    Artisan::call('health:schedule-check-heartbeat');
    $this->getJson('/health/scheduler')->assertOk()->assertJson(['status' => 'ok', 'renewal_run_hours_ago' => null]);

    $this->travel(40)->minutes();
    $this->getJson('/health/scheduler')->assertStatus(503);
});

test('the daily summary is sent to superadmins when there is something to report, and not otherwise', function (): void {
    Mail::fake();
    platformSuperadmin();

    $this->artisan('billing:daily-summary')->expectsOutput('Nothing to report.');
    Mail::assertNothingSent();

    $growth = Package::query()->where('slug', 'growth')->firstOrFail();
    $tenant = Tenant::factory()->create(['name' => 'Acme Events', 'package_id' => $growth->id]);
    Subscription::query()->create(['tenant_id' => $tenant->id, 'name' => 'default', 'provider_id' => 'ps_1', 'provider_status' => 'past_due', 'provider_plan' => 'growth_month', 'interval' => 'month', 'current_period_end' => now()->subDays(2), 'grace_ends_at' => now()->addDays(5)]);
    $comped = Tenant::factory()->create(['name' => 'Comped', 'package_id' => $growth->id, 'billing_complimentary' => true]);
    Subscription::query()->create(['tenant_id' => $comped->id, 'name' => 'default', 'provider_id' => 'ps_2', 'provider_status' => 'past_due', 'provider_plan' => 'growth_month', 'current_period_end' => now()->subDays(2)]);

    $this->artisan('billing:daily-summary')->assertSuccessful();

    Mail::assertSent(BillingDailySummaryMail::class, fn (BillingDailySummaryMail $mail): bool => $mail->hasTo('owner@miconvener.test')
        && count($mail->summary['past_due']) === 1
        && $mail->summary['past_due'][0]['tenant'] === 'Acme Events'
        && str_starts_with($mail->envelope()->subject, 'Needs attention: '));
});

test('the daily summary renders', function (): void {
    $html = (new BillingDailySummaryMail([
        'renewal_run' => ['at' => '11 October 2026 07:00', 'stale' => false, 'actions' => ['acme: charged GHS 99.00 (attempt 1)']],
        'past_due' => [['tenant' => 'Acme Events', 'plan' => 'Growth', 'grace_ends' => '17 October 2026', 'method' => 'Payment link']],
        'renewing_soon' => [['tenant' => 'UGMC', 'plan' => 'Starter', 'on' => '14 October 2026', 'amount' => 'GHS 29.00', 'method' => 'Visa card ending 4836']],
        'payments' => ['count' => 2, 'totals' => ['GHS 128.00']],
        'failed_jobs' => 1,
        'webhook_failures' => ['processing' => 0, 'signature' => 0],
    ]))->render();

    expect($html)->toContain('Acme Events')->toContain('17 October 2026')->toContain('GHS 29.00')->toContain('GHS 128.00')->toContain('1</strong> queued job');
});

test('the scheduler heartbeat, health checks and daily summary are scheduled without extra wake-ups', function (): void {
    $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->mapWithKeys(fn ($event): array => [mb_trim(Str::after((string) $event->command, 'artisan'), " '\"") => $event->expression]);

    expect($events->first(fn ($expression, $command) => str_contains($command, 'health:schedule-check-heartbeat')))->toBe('*/15 * * * *')
        ->and($events->first(fn ($expression, $command) => str_contains($command, 'billing:daily-summary')))->toBe('30 7 * * *')
        ->and($events->filter(fn (string $expression): bool => $expression === '* * * * *'))->toBeEmpty();
});
