<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Mail\Events\AutomatedNotificationMail;
use App\Models\Event;
use App\Models\EventNotificationLog;
use App\Models\EventNotificationRule;
use App\Models\EventRegistration;
use App\Models\TenantNotificationSetting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('host can view notification dashboard, audiences, and tenant quota', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'cardio-notify-2026',
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

test('multi-channel notification stages SMS and WhatsApp payloads ready for Omnichannel', function () {
    [$tenant, $user] = eventHost('acme');
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
        ->where('status', EventNotificationLog::STATUS_STAGED)->count())->toBe(2);

    $logs = EventNotificationLog::where('recipient_phone', '+233241234567')->get();
    expect($logs->count())->toBe(2);

    $smsLog = $logs->where('channel', 'sms')->first();
    expect($smsLog->status)->toBe(EventNotificationLog::STATUS_STAGED)
        ->and($smsLog->metadata['provider'])->toBe('omnichannel')
        ->and($smsLog->metadata['to'])->toBe('+233241234567')
        ->and($smsLog->metadata['channel'])->toBe('sms');

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

test('anti-abuse cooldown prevents duplicate notification spamming to the same recipient', function () {
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

    // Immediate second dispatch is rate limited and suppressed
    $secondResponse = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/notification-rules/{$rule->id}/dispatch", [], ['HTTP_HOST' => $host]);

    $secondResponse->assertOk();

    // A rate-limited recipient is suppressed before any row is written, so the
    // proof is that the second dispatch added nothing.
    expect(EventNotificationLog::where('event_id', $event->id)->count())->toBe(1);
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
