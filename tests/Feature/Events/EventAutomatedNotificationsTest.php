<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Mail\Events\AutomatedNotificationMail;
use App\Models\Event;
use App\Models\EventMaterial;
use App\Models\EventNotificationLog;
use App\Models\EventNotificationRule;
use App\Models\EventRegistration;
use App\Models\TenantNotificationSetting;
use App\Services\Notifications\AutomatedNotificationDispatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('registration and check-in occurrence rules target only the supplied matching registration', function (): void {
    Mail::fake();
    [$tenant] = eventHost('occurrence-rules');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $first = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'first@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);
    EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'second@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $ruleAttributes = [
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'target_role' => EventNotificationRule::ROLE_ATTENDEE,
        'channels' => ['email'],
        'subject' => 'Hello {name}',
        'body_template' => 'Your code is {ticket_code}.',
        'is_active' => true,
    ];
    EventNotificationRule::create($ruleAttributes + [
        'name' => 'Registration',
        'target_audience' => 'confirmed',
        'trigger_type' => EventNotificationRule::TRIGGER_ON_REGISTRATION,
    ]);

    $dispatcher = app(AutomatedNotificationDispatcher::class);
    expect($dispatcher->dispatchRegistrationRules($first))->toBe(1);

    $first->update(['status' => EventRegistration::STATUS_CHECKED_IN]);
    EventNotificationRule::create($ruleAttributes + [
        'name' => 'Check-in',
        'target_audience' => 'checked_in',
        'trigger_type' => EventNotificationRule::TRIGGER_ON_CHECKIN,
    ]);

    expect($dispatcher->dispatchCheckInRules($first->fresh(), 'gate-a'))->toBe(1)
        ->and(EventNotificationLog::query()->where('recipient_email', 'first@example.com')->count())->toBe(2)
        ->and(EventNotificationLog::query()->where('recipient_email', 'second@example.com')->count())->toBe(0);
});

test('occurrence rules ignore a registration outside their audience', function (): void {
    [$tenant] = eventHost('occurrence-audience');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);
    EventNotificationRule::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'name' => 'Checked in only',
        'target_role' => EventNotificationRule::ROLE_ATTENDEE,
        'target_audience' => 'checked_in',
        'trigger_type' => EventNotificationRule::TRIGGER_ON_REGISTRATION,
        'channels' => ['email'],
        'subject' => 'Welcome',
        'body_template' => 'Welcome.',
        'is_active' => true,
    ]);

    expect(app(AutomatedNotificationDispatcher::class)->dispatchRegistrationRules($registration))->toBe(0)
        ->and(EventNotificationLog::query()->count())->toBe(0);
});

test('material occurrence rules claim each matching recipient only once', function (): void {
    [$tenant] = eventHost('material-rules');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    EventRegistration::factory()->count(2)->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);
    EventNotificationRule::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'name' => 'Materials ready',
        'target_role' => EventNotificationRule::ROLE_ATTENDEE,
        'target_audience' => 'confirmed',
        'trigger_type' => EventNotificationRule::TRIGGER_ON_MATERIALS,
        'channels' => ['email'],
        'subject' => 'Materials ready',
        'body_template' => 'Download the materials.',
        'is_active' => true,
    ]);
    $material = EventMaterial::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    $dispatcher = app(AutomatedNotificationDispatcher::class);

    expect($dispatcher->dispatchMaterialRules($material))->toBe(2)
        ->and($dispatcher->dispatchMaterialRules($material))->toBe(0)
        ->and(EventNotificationLog::query()->count())->toBe(2);
});

test('host can view notification dashboard, audiences, and tenant quota', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'cardio-notify-2026',
    ]);
    EventNotificationLog::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'notification_type' => EventNotificationRule::TRIGGER_ON_REGISTRATION,
        'dedupe_key' => 'dashboard-history',
        'source_type' => EventRegistration::class,
        'source_id' => $event->id,
        'recipient_email' => 'history@example.com',
        'channel' => EventNotificationLog::CHANNEL_EMAIL,
        'status' => EventNotificationLog::STATUS_SENT,
        'attempts' => 1,
        'subject' => 'Welcome',
        'message' => 'Welcome.',
        'sent_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->getJson("http://{$host}/events/{$event->id}/notification-rules", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertJsonStructure([
        'rules',
        'audiences',
        'settings' => [
            'email_monthly_limit',
            'email_used_this_month',
            'sms_enabled',
            'whatsapp_enabled',
            'overage_billing_enabled',
        ],
        'presets',
        'recent_logs',
    ]);
    $response->assertJsonPath('recent_logs.0.notification_type', EventNotificationRule::TRIGGER_ON_REGISTRATION)
        ->assertJsonPath('recent_logs.0.source_type', EventRegistration::class)
        ->assertJsonPath('recent_logs.0.attempts', 1)
        ->assertJsonPath('recent_logs.0.status', EventNotificationLog::STATUS_SENT);

    $dayBefore = collect($response->json('presets'))->firstWhere('offset_amount', 1);
    expect($dayBefore['offset_unit'])->toBe('days')
        ->and($dayBefore['channels'])->toBe(['email', 'sms']);
});

test('host can create, update, toggle, and delete notification rules', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'surgical-reminder-2026',
    ]);

    // Create Rule
    $createResponse = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/notification-rules", [
            'name' => '1-Day Final Preparation Alert',
            'target_role' => 'attendee',
            'target_audience' => 'confirmed',
            'trigger_type' => 'scheduled_offset',
            'offset_direction' => 'before',
            'offset_amount' => 1,
            'offset_unit' => 'days',
            'channels' => ['email'],
            'subject' => 'Tomorrow: Surgical Masterclass begins!',
            'body_template' => 'Dear {name},\n\nSee you tomorrow at {venue}. Your ticket code is {ticket_code}.',
            'is_active' => true,
        ], ['HTTP_HOST' => $host]);

    $createResponse->assertCreated();
    $ruleId = $createResponse->json('rule.id');

    $rule = EventNotificationRule::findOrFail($ruleId);
    expect($rule->name)->toBe('1-Day Final Preparation Alert')
        ->and($rule->channels)->toBe(['email'])
        ->and($rule->is_active)->toBeTrue();

    // Toggle rule
    $toggleResponse = $this->actingAs($user)
        ->patchJson("http://{$host}/events/{$event->id}/notification-rules/{$rule->id}/toggle", [], ['HTTP_HOST' => $host]);

    $toggleResponse->assertOk();
    expect($rule->fresh()->is_active)->toBeFalse();

    // Update rule
    $updateResponse = $this->actingAs($user)
        ->putJson("http://{$host}/events/{$event->id}/notification-rules/{$rule->id}", [
            'name' => 'Updated Preparation Alert',
            'offset_amount' => 2,
        ], ['HTTP_HOST' => $host]);

    $updateResponse->assertOk();
    expect($rule->fresh()->name)->toBe('Updated Preparation Alert')
        ->and($rule->fresh()->offset_amount)->toBe(2);

    // Delete rule
    $deleteResponse = $this->actingAs($user)
        ->deleteJson("http://{$host}/events/{$event->id}/notification-rules/{$rule->id}", [], ['HTTP_HOST' => $host]);

    $deleteResponse->assertOk();
    expect(EventNotificationRule::find($ruleId))->toBeNull();
});

test('rule dispatch delivers direct emails with template placeholder interpolation', function () {
    Mail::fake();

    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'African Healthcare Summit',
        'address' => 'Kempinski Gold Coast City Hotel',
        'slug' => 'africa-health-2026',
        'starts_at' => now()->addDays(3),
    ]);

    // Create attendee registration
    $reg = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'full_name' => 'Dr. Kwame Bediako',
        'first_name' => 'Dr. Kwame',
        'last_name' => 'Bediako',
        'email' => 'kwame@ghanahealth.gov',
        'ticket_code' => 'TKT-MED-887',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $rule = EventNotificationRule::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'name' => 'Welcome Delegate',
        'target_role' => 'attendee',
        'target_audience' => 'confirmed',
        'trigger_type' => 'scheduled_offset',
        'offset_direction' => 'before',
        'offset_amount' => 3,
        'offset_unit' => 'days',
        'channels' => ['email'],
        'subject' => 'Welcome to {event_name}, {name}!',
        'body_template' => 'Hello {name},\n\nWe look forward to seeing you at {venue}. Ticket code: {ticket_code}.',
        'is_active' => true,
    ]);

    // Dispatch rule
    $dispatchResponse = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/notification-rules/{$rule->id}/dispatch", [], ['HTTP_HOST' => $host]);

    $dispatchResponse->assertOk();
    $dispatchResponse->assertJsonPath('stats.total_recipients', 1);

    // The endpoint queues the send and reports who it will go to; the outcome
    // is asserted on the log below rather than in the response.
    expect(EventNotificationLog::where('event_id', $event->id)
        ->where('status', EventNotificationLog::STATUS_SENT)->count())->toBe(1);

    // Verify email was sent with interpolated content. The gateway calls
    // sendNow() (see NotificationGatewayService::dispatch) precisely so this
    // is a real send, not merely a queued job -- assertSent, not assertQueued.
    Mail::assertSent(AutomatedNotificationMail::class, function (AutomatedNotificationMail $mail) {
        return $mail->hasTo('kwame@ghanahealth.gov') &&
               $mail->recipientName === 'Dr. Kwame Bediako' &&
               $mail->emailSubject === 'Welcome to African Healthcare Summit, Dr. Kwame Bediako!' &&
               str_contains($mail->renderedBody, 'Kempinski Gold Coast City Hotel') &&
               str_contains($mail->renderedBody, 'TKT-MED-887');
    });

    // Verify audit log
    $log = EventNotificationLog::where('recipient_email', 'kwame@ghanahealth.gov')->first();
    expect($log)->not->toBeNull()
        ->and($log->status)->toBe(EventNotificationLog::STATUS_SENT)
        ->and($log->channel)->toBe('email');
});

test('multi-channel notification sends SMS through the provider and stages WhatsApp', function () {
    config()->set('services.omnichannel', ['url' => 'https://messaging.test', 'token' => 'secret-token', 'sender_id' => 'MiConvener']);
    \Illuminate\Support\Facades\Http::fake(['messaging.test/*' => \Illuminate\Support\Facades\Http::response(['data' => ['campaign_id' => 'camp-1']], 202)]);

    [$tenant, $user] = eventHost('acme');
    \App\Models\TenantAddon::factory()->create([
        'tenant_id' => $tenant->id,
        'addon_type' => \App\Models\TenantAddon::TYPE_SMS_PACK,
        'quantity' => 10,
        'billing_interval' => \App\Models\TenantAddon::INTERVAL_ONE_OFF,
        'status' => \App\Models\TenantAddon::STATUS_ACTIVE,
    ]);
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Paediatric Symposium',
        'slug' => 'paeds-2026',
    ]);

    // Enable SMS and WhatsApp in tenant settings
    $settings = TenantNotificationSetting::forTenant($tenant->id);
    $settings->update([
        'sms_enabled' => true,
        'whatsapp_enabled' => true,
    ]);

    $reg = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'first_name' => 'Akosua',
        'last_name' => 'Mensah',
        'email' => 'akosua@clinic.org',
        'phone' => '+233241234567',
        'ticket_code' => 'TKT-AKO-12',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $rule = EventNotificationRule::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'name' => 'Multi-channel Pass Alert',
        'target_role' => 'attendee',
        'target_audience' => 'all',
        'trigger_type' => 'scheduled_offset',
        'offset_direction' => 'before',
        'offset_amount' => 1,
        'offset_unit' => 'days',
        'channels' => ['sms', 'whatsapp'],
        'subject' => 'Your Pass Code',
        'body_template' => 'Hi {name}, your pass is {ticket_code}.',
        'is_active' => true,
    ]);

    $dispatchResponse = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/notification-rules/{$rule->id}/dispatch", [], ['HTTP_HOST' => $host]);

    $dispatchResponse->assertOk();
    $dispatchResponse->assertJsonPath('stats.total_recipients', 1);

    expect(EventNotificationLog::where('event_id', $event->id)
        ->where('status', EventNotificationLog::STATUS_STAGED)->count())->toBe(1);

    $logs = EventNotificationLog::where('recipient_phone', '+233241234567')->get();
    expect($logs->count())->toBe(2);

    $smsLog = $logs->where('channel', 'sms')->first();
    expect($smsLog->status)->toBe(EventNotificationLog::STATUS_SENT)
        ->and($smsLog->metadata['provider_reference'])->toBe('camp-1');
    \Illuminate\Support\Facades\Http::assertSent(fn ($request): bool => str_contains($request->data()[0]['message'], 'your pass is TKT-AKO-12.')
        && $request->data()[0]['users'][0]['phone_number'] === '233241234567');

    $waLog = $logs->where('channel', 'whatsapp')->first();
    expect($waLog->status)->toBe(EventNotificationLog::STATUS_STAGED)
        ->and($waLog->metadata['provider'])->toBe('omnichannel')
        ->and($waLog->metadata['channel'])->toBe('whatsapp');
});

test('quota guardrail suppresses delivery when free monthly email limit is exceeded without overage billing', function () {
    Mail::fake();

    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'quota-test-2026',
    ]);

    // Set tenant email limit and simulate quota exhausted
    $settings = TenantNotificationSetting::forTenant($tenant->id);
    $settings->update([
        'email_monthly_limit' => 50,
        'email_used_this_month' => 50,
        'overage_billing_enabled' => false,
    ]);

    $reg = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'first_name' => 'Abena',
        'last_name' => 'Kumi',
        'email' => 'abena@test.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $rule = EventNotificationRule::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'name' => 'Monthly Newsletter',
        'target_role' => 'attendee',
        'target_audience' => 'all',
        'trigger_type' => 'scheduled_offset',
        'offset_direction' => 'before',
        'offset_amount' => 1,
        'offset_unit' => 'days',
        'channels' => ['email'],
        'subject' => 'Conference News',
        'body_template' => 'Hello {name}!',
        'is_active' => true,
    ]);

    $dispatchResponse = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/notification-rules/{$rule->id}/dispatch", [], ['HTTP_HOST' => $host]);

    $dispatchResponse->assertOk();
    expect(EventNotificationLog::where('event_id', $event->id)
        ->where('status', EventNotificationLog::STATUS_SUPPRESSED_QUOTA)->count())->toBe(1);

    // Assert mail was NOT dispatched
    Mail::assertNothingSent();

    // Verify suppressed log
    $log = EventNotificationLog::where('recipient_email', 'abena@test.com')->first();
    expect($log->status)->toBe(EventNotificationLog::STATUS_SUPPRESSED_QUOTA);

    // Now enable overage billing
    $settings->update(['overage_billing_enabled' => true]);

    $secondDispatch = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/notification-rules/{$rule->id}/dispatch", [], ['HTTP_HOST' => $host]);

    $secondDispatch->assertOk();
    expect(EventNotificationLog::where('event_id', $event->id)
        ->where('status', EventNotificationLog::STATUS_SENT)->count())->toBe(1);
    // sendNow() is used deliberately (see NotificationGatewayService::dispatch).
    Mail::assertSent(AutomatedNotificationMail::class);
});

test('separate manual sends are not suppressed as unrelated duplicate notifications', function () {
    Mail::fake();

    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'spam-protection-2026',
    ]);

    $reg = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'first_name' => 'Kojo',
        'last_name' => 'Antwi',
        'email' => 'kojo@music.org',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $rule = EventNotificationRule::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'name' => 'Session Announcement',
        'target_role' => 'attendee',
        'target_audience' => 'all',
        'trigger_type' => 'scheduled_offset',
        'offset_direction' => 'before',
        'offset_amount' => 1,
        'offset_unit' => 'days',
        'channels' => ['email'],
        'subject' => 'Session Announcement',
        'body_template' => 'Hello {name}!',
        'is_active' => true,
    ]);

    // First dispatch succeeds
    $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/notification-rules/{$rule->id}/dispatch", [], ['HTTP_HOST' => $host])
        ->assertJsonPath('stats.total_recipients', 1);

    expect(EventNotificationLog::where('event_id', $event->id)
        ->where('status', EventNotificationLog::STATUS_SENT)->count())->toBe(1);

    // A second explicit organizer action is a separate occurrence. The old
    // recipient cooldown incorrectly suppressed it merely because the address
    // had received some other event email recently.
    $secondResponse = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/notification-rules/{$rule->id}/dispatch", [], ['HTTP_HOST' => $host]);

    $secondResponse->assertOk();

    expect(EventNotificationLog::where('event_id', $event->id)->count())->toBe(2);
    Mail::assertSent(AutomatedNotificationMail::class, 2);
});

test('scheduled console command scans and dispatches due notification rules', function () {
    Mail::fake();

    [$tenant, $user] = eventHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'National Science Congress',
        'starts_at' => now()->addDays(2),
        'slug' => 'science-congress-2026',
    ]);

    $reg = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'first_name' => 'Ama',
        'last_name' => 'Serwaa',
        'email' => 'ama@stem.org',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    // Rule scheduled for 2 days before event (which matches now!)
    $rule = EventNotificationRule::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'name' => '2-Day Scheduled Alert',
        'target_role' => 'attendee',
        'target_audience' => 'confirmed',
        'trigger_type' => 'scheduled_offset',
        'offset_direction' => 'before',
        'offset_amount' => 2,
        'offset_unit' => 'days',
        'channels' => ['email'],
        'subject' => 'Two Days to Congress!',
        'body_template' => 'Dear {name}, Congress begins in 2 days.',
        'is_active' => true,
        'last_dispatched_at' => null,
    ]);

    expect($rule->isDue())->toBeTrue();

    // Run the artisan console command
    Artisan::call('app:dispatch-automated-notifications');

    // Confirm rule dispatched
    expect($rule->fresh()->last_dispatched_at)->not->toBeNull();
    // sendNow() is used deliberately (see NotificationGatewayService::dispatch).
    Mail::assertSent(AutomatedNotificationMail::class, function (AutomatedNotificationMail $mail) {
        return $mail->hasTo('ama@stem.org');
    });
});

test('a scheduled reminder that has already been sent does not send again', function () {
    Mail::fake();

    [$tenant, $user] = eventHost('acme');

    // An event that finished two months ago. Its reminder rule is still active,
    // as an organiser has no reason to go back and switch off a rule for an
    // event that is over.
    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Past Congress',
        'starts_at' => now()->subDays(60),
        'ends_at' => now()->subDays(59),
        'slug' => 'past-congress-2026',
    ]);

    EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'ama@stem.org',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $rule = EventNotificationRule::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'name' => '2-Day Scheduled Alert',
        'target_role' => 'attendee',
        'target_audience' => 'confirmed',
        'trigger_type' => 'scheduled_offset',
        'offset_direction' => 'before',
        'offset_amount' => 2,
        'offset_unit' => 'days',
        'channels' => ['email'],
        'subject' => 'Two Days to Congress!',
        'body_template' => 'Dear {name}, Congress begins in 2 days.',
        'is_active' => true,
        // It fired at the right time, two months ago.
        'last_dispatched_at' => now()->subDays(62),
    ]);

    expect($rule->isDue())->toBeFalse();

    Artisan::call('app:dispatch-automated-notifications');

    Mail::assertNothingSent();
});

test('a reminder missed by a short scheduler outage still sends, an older one does not', function () {
    [$tenant] = eventHost('acme');

    $ruleForTargetAgo = function (int $hoursAgo) use ($tenant): EventNotificationRule {
        $event = Event::factory()->published()->create([
            'tenant_id' => $tenant->id,
            // A "2 hours before" rule on this event targets $hoursAgo in the past.
            'starts_at' => now()->subHours($hoursAgo)->addHours(2),
            'slug' => 'outage-'.$hoursAgo.'-2026',
        ]);

        return EventNotificationRule::create([
            'tenant_id' => $tenant->id,
            'event_id' => $event->id,
            'name' => 'Two Hour Alert',
            'target_role' => 'attendee',
            'target_audience' => 'confirmed',
            'trigger_type' => 'scheduled_offset',
            'offset_direction' => 'before',
            'offset_amount' => 2,
            'offset_unit' => 'hours',
            'channels' => ['email'],
            'subject' => 'Starting soon',
            'body_template' => 'Dear {name}, we begin shortly.',
            'is_active' => true,
            'last_dispatched_at' => null,
        ]);
    };

    // The scheduler runs every fifteen minutes, but the environment scales to
    // zero, so a gap of a few hours is plausible and must not lose the send.
    expect($ruleForTargetAgo(3)->isDue())->toBeTrue();

    // Beyond a day the reminder is stale: telling someone an event starts in
    // two hours, a day and a half late, is worse than staying quiet.
    expect($ruleForTargetAgo(36)->isDue())->toBeFalse();
});

test('rescheduling a rule that already fired arms it again, renaming it does not', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'starts_at' => now()->addDays(5),
        'slug' => 'rearm-congress-2026',
    ]);

    $makeRule = fn (): EventNotificationRule => EventNotificationRule::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'name' => 'Week Ahead Alert',
        'target_role' => 'attendee',
        'target_audience' => 'confirmed',
        'trigger_type' => 'scheduled_offset',
        'offset_direction' => 'before',
        'offset_amount' => 6,
        'offset_unit' => 'days',
        'channels' => ['email'],
        'subject' => 'Coming up',
        'body_template' => 'Dear {name}, see you soon.',
        'is_active' => true,
        'last_dispatched_at' => now()->subDay(),
    ]);

    // Moving the rule to a new moment is a fresh instruction, so the send it
    // already made must not suppress the one the organiser has just asked for.
    $rescheduled = $makeRule();
    $this->actingAs($user)
        ->putJson("http://{$host}/events/{$event->id}/notification-rules/{$rescheduled->id}", [
            'offset_amount' => 2,
        ], ['HTTP_HOST' => $host])
        ->assertOk();

    expect($rescheduled->fresh()->last_dispatched_at)->toBeNull();

    // Correcting the wording is not, or an organiser fixing a typo would mail
    // everyone a second copy.
    $renamed = $makeRule();
    $this->actingAs($user)
        ->putJson("http://{$host}/events/{$event->id}/notification-rules/{$renamed->id}", [
            'subject' => 'Coming up soon',
        ], ['HTTP_HOST' => $host])
        ->assertOk();

    expect($renamed->fresh()->last_dispatched_at)->not->toBeNull();
});

test('scanning for due rules does not query once per published event', function () {
    [$tenant] = eventHost('acme');

    // Ten published events, each carrying an active rule that is not yet due.
    // Nothing dispatches, so what is measured is the scan alone.
    foreach (range(1, 10) as $index) {
        $event = Event::factory()->published()->create([
            'tenant_id' => $tenant->id,
            'starts_at' => now()->addDays(30),
            'slug' => "scan-congress-{$index}",
        ]);

        EventNotificationRule::create([
            'tenant_id' => $tenant->id,
            'event_id' => $event->id,
            'name' => "Scan Rule {$index}",
            'target_role' => 'attendee',
            'target_audience' => 'confirmed',
            'trigger_type' => 'scheduled_offset',
            'offset_direction' => 'before',
            'offset_amount' => 1,
            'offset_unit' => 'days',
            'channels' => ['email'],
            'subject' => 'Coming up',
            'body_template' => 'Dear {name}, see you soon.',
            'is_active' => true,
            'last_dispatched_at' => null,
        ]);
    }

    DB::connection('landlord')->flushQueryLog();
    DB::connection('landlord')->enableQueryLog();

    Artisan::call('app:dispatch-automated-notifications');

    $queryCount = count(DB::connection('landlord')->getQueryLog());
    DB::connection('landlord')->disableQueryLog();

    // The scheduler runs this every fifteen minutes across every tenant, so the
    // cost of a scan must not grow with the number of events on the platform.
    expect($queryCount)->toBeLessThanOrEqual(2);
});

test('the rules payload exposes the fields the console needs to show a reminder as sent', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'payload-congress-2026',
    ]);

    EventNotificationRule::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'name' => 'Week Ahead Alert',
        'target_role' => 'attendee',
        'target_audience' => 'confirmed',
        'trigger_type' => 'scheduled_offset',
        'offset_direction' => 'before',
        'offset_amount' => 7,
        'offset_unit' => 'days',
        'channels' => ['email'],
        'subject' => 'Coming up',
        'body_template' => 'Dear {name}, see you soon.',
        'is_active' => true,
        'last_dispatched_at' => now()->subDay(),
    ]);

    // The rule card decides whether to show "Sent", and the note explaining that
    // a scheduled reminder fires once, from exactly these two fields.
    $this->actingAs($user)
        ->getJson("http://{$host}/events/{$event->id}/notification-rules", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJsonStructure(['rules' => ['*' => ['trigger_type', 'last_dispatched_at']]])
        ->assertJsonPath('rules.0.trigger_type', 'scheduled_offset');
});

test('the reminder email renders, so a dispatched notification can actually be delivered', function () {
    [$tenant] = eventHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'National Science Congress',
        'slug' => 'render-congress-2026',
    ]);

    // Every other test here fakes the mailer, so the template is never built.
    // That is how a mailable whose view could not render at all shipped: it
    // fires, the dispatcher logs a failure, and nobody receives anything.
    $html = (new AutomatedNotificationMail(
        event: $event,
        recipientName: 'Ama Serwaa',
        emailSubject: 'Two Days to Congress!',
        renderedBody: 'Congress begins in 2 days.',
        actionUrl: 'https://example.test/pass',
        actionLabel: 'View Digital Pass',
    ))->render();

    expect($html)
        ->toContain('Congress begins in 2 days')
        ->toContain('View Digital Pass')
        ->toContain('National Science Congress');
});
