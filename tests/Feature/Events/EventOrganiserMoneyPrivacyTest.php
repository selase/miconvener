<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventStaffAssignment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    $this->tenant = Tenant::factory()->create(['slug' => 'organisermoney', 'isolation_mode' => 'shared']);
    setPermissionsTeamId($this->tenant->id);
    $this->organiser = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->organiser->assignRole('Event Organiser');
    $this->tenant->users()->attach($this->organiser->id);
    $this->event = Event::factory()->create(['tenant_id' => $this->tenant->id, 'ticket_price' => 1200, 'currency' => 'GHS', 'fee_bearer' => 'attendee', 'offline_payment_bank_name' => 'Private Bank']);
    EventStaffAssignment::create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'user_id' => $this->organiser->id]);
    $this->host = 'organisermoney.'.mb_ltrim((string) config('session.domain'), '.');
});

it('excludes private financial data from the organiser workspace', function (): void {
    $this->actingAs($this->organiser)->get("http://{$this->host}/events/{$this->event->id}")
        ->assertOk()->assertInertia(fn ($page) => $page
        ->where('canManageEventMoney', false)
        ->missing('event.fee_bearer')->missing('event.fee_preview')
        ->missing('event.effective_platform_fee_percentage')->missing('event.offline_payment_bank_name')
        ->missing('stats.collected')->missing('stats.platform_fees')->missing('settlementMode'));
});

it('preserves omitted event money settings during a nonfinancial edit', function (): void {
    $payload = ['name' => 'Updated setup', 'status' => 'draft', 'starts_at' => '2027-05-01 10:00', 'ends_at' => '2027-05-01 14:00', 'timezone' => 'Africa/Accra', 'location_type' => 'in_person', 'address' => 'Accra'];
    $this->actingAs($this->organiser)->putJson("http://{$this->host}/events/{$this->event->id}", $payload)->assertSuccessful();
    expect($this->event->fresh())->name->toBe('Updated setup')->ticket_price->toBe(1200)->fee_bearer->toBe('attendee')->offline_payment_bank_name->toBe('Private Bank');
});

it('refuses forged changes to event money settings', function (string $field, mixed $value): void {
    $this->actingAs($this->organiser)->putJson("http://{$this->host}/events/{$this->event->id}", [$field => $value])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with(['price' => ['ticket_price', 0], 'currency' => ['currency', 'USD'], 'bearer' => ['fee_bearer', 'organizer'], 'bank' => ['offline_payment_bank_name', 'Another bank'], 'venue agreement link' => ['venue_booking_id', (string) Illuminate\Support\Str::uuid()]]);

it('limits list counts and recovery rows to assigned events', function (): void {
    $other = Event::factory()->create(['tenant_id' => $this->tenant->id]);
    App\Models\EventRegistration::factory()->confirmed()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id]);
    App\Models\EventRegistration::factory()->confirmed()->create(['tenant_id' => $this->tenant->id, 'event_id' => $other->id]);
    $other->update(['purge_at' => now()->addDay()]);
    $other->delete();
    $this->actingAs($this->organiser)->get("http://{$this->host}/events")->assertOk()
        ->assertInertia(fn ($page) => $page->has('events', 1)->where('stats.total_registered', 1)->missing('stats.collected_this_year')->has('recentlyDeletedEvents', 0));
});

it('does not offer or export private financial registration columns', function (): void {
    $response = $this->actingAs($this->organiser)->getJson("http://{$this->host}/events/{$this->event->id}/data/reports");
    $response->assertOk();
    expect(collect($response->json('available_columns'))->pluck('key')->all())->not->toContain('platform_fee', 'external_amount');
    $this->getJson("http://{$this->host}/events/{$this->event->id}/reports/registrations?columns[]=platform_fee")->assertUnprocessable();
});

it('retains public admission status but omits payment proofs and commission from guests', function (): void {
    App\Models\EventRegistration::factory()->confirmed()->create([
        'tenant_id' => $this->tenant->id, 'event_id' => $this->event->id,
        'amount' => 1200, 'platform_fee_amount' => 120, 'offline_payment_reference' => 'private-ref',
        'offline_payment_notes' => 'private note', 'offline_payment_proof_path' => 'events/proof.jpg',
    ]);
    $this->actingAs($this->organiser)->get("http://{$this->host}/events/{$this->event->id}/guests")
        ->assertOk()->assertInertia(fn ($page) => $page
        ->where('registrations.0.amount', 1200)->where('registrations.0.status', 'confirmed')
        ->missing('registrations.0.platform_fee_amount')->missing('registrations.0.offline_payment_reference')
        ->missing('registrations.0.offline_payment_notes')->missing('registrations.0.has_offline_proof'));
});

it('only lets organisers cancel admissions that cannot refund money', function (array $attributes, bool $allowed): void {
    $registration = App\Models\EventRegistration::factory()->confirmed()->create($attributes + ['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id]);
    $response = $this->actingAs($this->organiser)->postJson("http://{$this->host}/events/{$this->event->id}/registrations/{$registration->id}/cancel");
    if ($allowed) {
        $response->assertOk();
        expect($registration->fresh()->status)->toBe('cancelled');
    } else {
        $response->assertForbidden();
        expect($registration->fresh()->status)->toBe('confirmed');
    }
})->with([
    'native paid' => [['amount' => 1200, 'payment_reference' => null], false],
    'native free' => [['amount' => 0, 'payment_reference' => null], true],
    'imported' => [['amount' => 0, 'payment_reference' => null, 'registration_source' => 'external_import', 'admission_payment_source' => 'paid_elsewhere'], true],
    'member benefit' => [['amount' => 0, 'payment_reference' => null, 'member_benefit_required' => true], false],
]);

it('stops queued organiser blasts after event assignment removal', function (): void {
    Illuminate\Support\Facades\Mail::fake();
    App\Models\EventRegistration::factory()->confirmed()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id]);
    $blast = App\Models\EventBlast::query()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'sent_by' => $this->organiser->id, 'subject' => 'News', 'body' => 'Hello', 'audience' => 'all', 'status' => 'scheduled']);
    $job = new App\Jobs\Events\SendEventBlastJob($blast);
    EventStaffAssignment::where('user_id', $this->organiser->id)->delete();
    $job->handle();
    Illuminate\Support\Facades\Mail::assertNothingQueued();
});

it('rechecks organiser assignment at actual queued email delivery and refunds credit', function (): void {
    Illuminate\Support\Facades\Mail::fake();
    $this->tenant->features()->create(['feature_key' => 'email_credits', 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => 10]]);
    App\Models\EventRegistration::factory()->confirmed()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id]);
    $blast = App\Models\EventBlast::query()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'sent_by' => $this->organiser->id, 'subject' => 'News', 'body' => 'Hello', 'audience' => 'all', 'status' => 'scheduled']);
    (new App\Jobs\Events\SendEventBlastJob($blast))->handle();
    $mail = Illuminate\Support\Facades\Mail::queued(App\Mail\Events\EventBlastMail::class)->first();
    expect($mail)->not->toBeNull();
    EventStaffAssignment::where('user_id', $this->organiser->id)->delete();
    $sent = false;
    app(App\Services\Community\EventAnnouncementDelivery::class)->sendQueuedEmail($mail, function () use (&$sent): ?\Illuminate\Mail\SentMessage {
        $sent = true;

        return null;
    });
    expect($sent)->toBeFalse()->and(app(App\Services\Billing\EmailAllowance::class)->remaining($this->tenant))->toBe(10);
});

it('rechecks organiser assignment before actual SMS transport', function (): void {
    Illuminate\Support\Facades\Mail::fake();
    Illuminate\Support\Facades\Queue::fake([App\Jobs\Notifications\SendSmsBatchJob::class]);
    Illuminate\Support\Facades\Http::preventStrayRequests();
    foreach (['email_credits', 'sms_credits'] as $key) {
        $this->tenant->features()->create(['feature_key' => $key, 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => 10]]);
    }
    App\Models\TenantNotificationSetting::forTenant($this->tenant->id)->update(['sms_enabled' => true]);
    App\Models\EventRegistration::factory()->confirmed()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'phone' => '0241234567']);
    $blast = App\Models\EventBlast::query()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'sent_by' => $this->organiser->id, 'subject' => 'News', 'body' => 'Hello', 'audience' => 'all', 'status' => 'scheduled', 'send_sms' => true]);
    (new App\Jobs\Events\SendEventBlastJob($blast))->handle();
    $log = App\Models\EventNotificationLog::query()->where('channel', 'sms')->firstOrFail();
    expect($log->metadata['requires_scoped_actor'])->toBeTrue();
    EventStaffAssignment::where('user_id', $this->organiser->id)->delete();
    (new App\Jobs\Notifications\SendSmsBatchJob($this->tenant->id, [$log->id]))->handle(app(App\Services\Notifications\NotificationGatewayService::class));
    Illuminate\Support\Facades\Http::assertNothingSent();
    expect($log->fresh()->status)->toBe(App\Models\EventNotificationLog::STATUS_UNSUBSCRIBED);
});

it('preserves the organiser actor restriction when their role is removed after dispatch', function (): void {
    Illuminate\Support\Facades\Mail::fake();
    App\Models\EventRegistration::factory()->confirmed()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id]);
    $blast = App\Models\EventBlast::query()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'sent_by' => $this->organiser->id, 'subject' => 'News', 'body' => 'Hello', 'audience' => 'all', 'status' => 'scheduled']);
    $job = new App\Jobs\Events\SendEventBlastJob($blast);
    $this->organiser->removeRole('Event Organiser');
    $job->handle();
    Illuminate\Support\Facades\Mail::assertNothingQueued();
});

it('returns only safe admission fields in organiser participant group members', function (): void {
    $registration = App\Models\EventRegistration::factory()->confirmed()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'payment_reference' => 'secret-group-payment', 'platform_fee_amount' => 120, 'offline_payment_notes' => 'secret-group-proof']);
    $group = App\Models\EventParticipantGroup::query()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'name' => 'Guests', 'slug' => 'guests', 'type' => 'manual']);
    $group->members()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'registration_id' => $registration->id, 'matched_at' => now(), 'is_manual' => true]);
    $this->actingAs($this->organiser)->getJson("http://{$this->host}/events/{$this->event->id}/participant-groups/{$group->id}")
        ->assertOk()->assertJsonPath('members.data.0.registration.id', $registration->id)
        ->assertJsonMissingPath('members.data.0.registration.payment_reference')
        ->assertJsonMissingPath('members.data.0.registration.platform_fee_amount')
        ->assertJsonMissingPath('members.data.0.registration.offline_payment_notes');
    $csv = $this->get("http://{$this->host}/events/{$this->event->id}/participant-groups/{$group->id}/export")->assertOk()->streamedContent();
    expect($csv)->not->toContain('secret-group-payment', 'secret-group-proof', 'Platform Fee');
});

it('lets organisers create and edit speakers inside their assigned event', function (): void {
    $response = $this->actingAs($this->organiser)->postJson("http://{$this->host}/events/{$this->event->id}/speaker-profiles", ['name' => 'Ama Speaker', 'email' => 'ama@example.test'])->assertCreated();
    $speakerId = $response->json('id');
    expect($this->event->speakers()->whereKey($speakerId)->exists())->toBeTrue();
    $this->putJson("http://{$this->host}/events/{$this->event->id}/speaker-profiles/{$speakerId}", ['name' => 'Ama Updated', 'email' => 'ama@example.test'])->assertOk()->assertJsonPath('name', 'Ama Updated');
});

it('does not let organisers alter unlinked or shared speaker profiles', function (): void {
    $unlinked = App\Models\Speaker::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->actingAs($this->organiser)->postJson("http://{$this->host}/events/{$this->event->id}/speakers", ['speaker_id' => $unlinked->id])->assertForbidden();
    $this->actingAs($this->organiser)->putJson("http://{$this->host}/events/{$this->event->id}/speaker-profiles/{$unlinked->id}", ['name' => 'Forged', 'email' => 'ama@example.test'])->assertNotFound();
    $otherEvent = Event::factory()->create(['tenant_id' => $this->tenant->id]);
    foreach ([$this->event, $otherEvent] as $event) {
        $event->speakers()->attach($unlinked->id, ['id' => (string) Illuminate\Support\Str::uuid(), 'tenant_id' => $this->tenant->id]);
    }
    $this->putJson("http://{$this->host}/events/{$this->event->id}/speaker-profiles/{$unlinked->id}", ['name' => 'Forged', 'email' => 'ama@example.test'])->assertUnprocessable();
    expect($unlinked->fresh()->name)->not->toBe('Forged');
    $this->getJson("http://{$this->host}/events/{$this->event->id}/speaker-profiles")->assertOk()->assertJsonCount(1);
});

it('keeps finance private when an organiser also holds custom finance permissions', function (): void {
    $role = App\Models\Role::create(['name' => 'Extra finance', 'guard_name' => 'web', 'tenant_id' => $this->tenant->id]);
    $role->givePermissionTo('read finance');
    $this->organiser->assignRole($role);
    App\Models\EventRegistration::factory()->confirmed()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'external_amount' => 4444, 'external_currency' => 'GHS']);
    $this->actingAs($this->organiser)->get("http://{$this->host}/events/{$this->event->id}/guests")->assertOk()->assertInertia(fn ($page) => $page->where('registrations.0.external_amount', null));
    $this->getJson("http://{$this->host}/events/{$this->event->id}/data/reports")->assertOk()->assertJsonPath('payment_summary', null);
    $csv = $this->get("http://{$this->host}/events/{$this->event->id}/guests/export")->assertOk()->streamedContent();
    expect($csv)->not->toContain('4444');
});
