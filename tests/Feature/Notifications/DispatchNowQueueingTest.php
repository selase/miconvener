<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Jobs\Notifications\DispatchNotificationRuleJob;
use App\Models\Event;
use App\Models\EventNotificationLog;
use App\Models\EventNotificationRule;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\TenantNotificationSetting;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/**
 * The gateway mails with sendNow() so its error handling, SENT status and
 * sent_at are claims about work actually done. That makes each send a real
 * blocking SMTP round-trip, which cannot happen inside a web request: a rule
 * aimed at a few hundred attendees would run for minutes and time out having
 * already mailed some of them.
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function ruleDispatchScenario(string $slug, int $registrations = 3): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    TenantNotificationSetting::create([
        'tenant_id' => $tenant->id,
        'sms_enabled' => true,
        'whatsapp_enabled' => true,
        'email_monthly_limit' => 500,
        'email_used_this_month' => 0,
        'sms_cost_rate' => 500,
        'whatsapp_cost_rate' => 300,
        'email_overage_rate' => 0,
        'overage_billing_enabled' => false,
        'anti_abuse_cooldown_minutes' => 0,
        'month_reset_at' => now()->startOfMonth(),
    ]);

    EventRegistration::factory()->count($registrations)->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $rule = EventNotificationRule::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'name' => 'Doors open reminder',
        'subject' => 'Doors open at 9',
        'body_template' => 'See you at {{name}}.',
        'channels' => ['email'],
        'target_role' => 'attendee',
        'target_audience' => 'all',
        'is_active' => true,
    ]);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $event, $rule, $user, $slug.'.'.mb_ltrim((string) config('session.domain'), '.')];
}

test('dispatch now queues the send instead of mailing inside the request', function () {
    Queue::fake();
    [$tenant, $event, $rule, $user, $host] = ruleDispatchScenario('queue-dispatch');

    $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/notification-rules/{$rule->id}/dispatch", [], ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJsonPath('stats.queued', true)
        ->assertJsonPath('stats.total_recipients', 3);

    Queue::assertPushed(DispatchNotificationRuleJob::class, fn ($job): bool => $job->rule->id === $rule->id);

    // Nothing may be sent in the request cycle -- that is the whole point.
    expect(EventNotificationLog::where('event_id', $event->id)->count())->toBe(0);
});

test('the queued job carries its tenant so the worker restores context', function () {
    [$tenant, $event, $rule] = ruleDispatchScenario('queue-tenant');

    app(\App\Services\Tenancy\TenantContext::class)->setTenant($tenant);

    // A worker has no tenant context of its own: without the captured tenantId
    // the global TenantScope is a no-op there and message links lose their
    // subdomain. The tenant is captured in the constructor rather than by the
    // TenantAware trait, whose __sleep() never runs on a queued job because
    // SerializesModels defines __serialize() and PHP prefers it.
    $job = new DispatchNotificationRuleJob($rule);
    $revived = unserialize(serialize($job));

    expect($job->tenantId)->toBe($tenant->id);
    expect($revived->tenantId)->toBe($tenant->id);
    expect($job->middleware())->toHaveCount(1);
    expect($job->middleware()[0])->toBeInstanceOf(\App\Jobs\Middleware\TenantAwareJob::class);
});

test('running the job actually delivers to every recipient', function () {
    Mail::fake();
    [$tenant, $event, $rule, $user, $host] = ruleDispatchScenario('queue-run', registrations: 4);

    app(DispatchNotificationRuleJob::class, ['rule' => $rule])->handle(
        app(\App\Services\Notifications\AutomatedNotificationDispatcher::class)
    );

    expect(EventNotificationLog::where('event_id', $event->id)->count())->toBe(4);
    expect($rule->fresh()->last_dispatched_at)->not->toBeNull();
});
