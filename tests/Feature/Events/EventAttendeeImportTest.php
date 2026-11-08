<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    $this->tenant = Tenant::factory()->create(['slug' => 'imports', 'isolation_mode' => 'shared']);
    $this->host = 'imports.miconvener.test';
    $this->event = Event::factory()->create(['tenant_id' => $this->tenant->id, 'capacity' => 20]);
    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($this->admin->id);
    setPermissionsTeamId($this->tenant->id);
    $this->admin->assignRole('Org Admin');
    $this->actingAs($this->admin);
    $this->url = "http://{$this->host}/events/{$this->event->id}/attendee-imports";
    Mail::fake();
    Queue::fake();
    $this->invitationRevisions = fn (string $id): array => collect($this->getJson($this->url.'/'.$id, ['HTTP_HOST' => $this->host])->json('rows'))->whereNotNull('registration_id')->pluck('invitation_revision', 'registration_id')->all();
    $this->preview = fn (string $csv, array $extra = []) => $this->postJson($this->url.'/preview', ['file' => UploadedFile::fake()->createWithContent('attendees.csv', $csv), ...$extra], ['HTTP_HOST' => $this->host]);
});

test('private CSV preview freezes canonical rows with strict declared money and no admission or mail', function (): void {
    $response = ($this->preview)("\xEF\xBB\xBFfull_name,email,payment_status,external_amount,currency\r\n\"Ada, One\",ADA@example.test,paid_elsewhere,12.34,GHS\r\n");
    $response->assertOk()->assertJsonPath('status', 'preview')->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('rows.0.data.email', 'ada@example.test')->assertJsonPath('rows.0.data.external_amount', 1234)->assertJsonPath('rows.0.status', 'valid');
    expect(EventRegistration::count())->toBe(0);
    Mail::assertNothingSent();
});

test('mapped headers and default free admission are supported', function (): void {
    ($this->preview)("Name,Address\nAda,ada@example.test\n", ['mapping' => ['full_name' => 'Name', 'email' => 'Address']])->assertOk()->assertJsonPath('rows.0.data.payment_status', 'free');
});

test('parser rejects malformed headers and records', function (string $csv): void {
    ($this->preview)($csv)->assertUnprocessable();
})->with(["full_name,email,email\nAda,a@b.test,a@b.test", "full_name,email\n\"Ada,a@b.test", "full_name,email\nAda,a@b.test,extra", "full_name,email\nAda,a\0b"]);

test('money validation never rounds or accepts negative declarations', function (string $amount): void {
    ($this->preview)("full_name,email,payment_status,external_amount,currency\nAda,ada@example.test,paid_elsewhere,\"{$amount}\",GHS")->assertOk()->assertJsonPath('rows.0.status', 'error');
})->with(['-1', '1.234', '1e3', '1,000', '=1+2']);

test('shared emails are conflicts while exact repeat rows are duplicates', function (): void {
    ($this->preview)("full_name,email\nAda,ada@example.test\nAda,ADA@example.test\nSomeone else,ada@example.test")->assertOk()->assertJsonPath('rows.0.status', 'conflict')->assertJsonPath('rows.1.status', 'duplicate')->assertJsonPath('rows.2.status', 'conflict');
});

test('preview requires update permission and event ownership', function (): void {
    $reader = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($reader->id);
    $this->actingAs($reader);
    ($this->preview)("full_name,email\nAda,ada@example.test")->assertForbidden();
    $this->actingAs($this->admin);
    $foreign = Event::factory()->create();
    $this->postJson("http://{$this->host}/events/{$foreign->id}/attendee-imports/preview", ['file' => UploadedFile::fake()->createWithContent('a.csv', "full_name,email\nAda,ada@example.test")], ['HTTP_HOST' => $this->host])->assertNotFound();
});

test('durable confirmation admits once without ownership proof native money or invitation', function (): void {
    $preview = ($this->preview)("full_name,email,payment_status,external_amount,currency,external_reference\nAda,ada@example.test,paid_elsewhere,12.34,GHS,old-1")->assertOk()->json();
    $body = ['operation_key' => (string) Illuminate\Support\Str::uuid(), 'rows' => [$preview['rows'][0]['id']]];
    $this->postJson($this->url.'/'.$preview['id'].'/confirm', $body, ['HTTP_HOST' => $this->host])->assertOk()->assertJsonPath('status', 'queued');
    app(App\Services\Events\AttendeeImport::class)->process($preview['id']);
    $registration = EventRegistration::firstOrFail();
    expect($registration->status)->toBe('confirmed')->and($registration->ticket_code)->not->toBeNull()->and($registration->email_verified_at)->toBeNull()->and($registration->amount)->toBe(0)->and($registration->payment_reference)->toBeNull()->and($registration->external_amount)->toBe(1234);
    $ticket = $registration->ticket_code;
    $this->postJson($this->url.'/'.$preview['id'].'/confirm', $body, ['HTTP_HOST' => $this->host])->assertOk();
    app(App\Services\Events\AttendeeImport::class)->process($preview['id']);
    expect(EventRegistration::count())->toBe(1)->and($registration->fresh()->ticket_code)->toBe($ticket);
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    expect(App\Models\EventLedgerEntry::count())->toBe(0)->and(App\Models\LedgerTransaction::count())->toBe(0);
});

test('confirmation refuses expired previews changed selections and foreign row identifiers', function (): void {
    $preview = ($this->preview)("full_name,email\nAda,ada@example.test\nBen,ben@example.test")->json();
    $key = (string) Illuminate\Support\Str::uuid();
    $this->postJson($this->url.'/'.$preview['id'].'/confirm', ['operation_key' => $key, 'rows' => [$preview['rows'][0]['id']]], ['HTTP_HOST' => $this->host])->assertOk();
    $this->postJson($this->url.'/'.$preview['id'].'/confirm', ['operation_key' => $key, 'rows' => [$preview['rows'][1]['id']]], ['HTTP_HOST' => $this->host])->assertConflict();
    $other = ($this->preview)("full_name,email\nC,c@example.test")->json();
    $this->postJson($this->url.'/'.$other['id'].'/confirm', ['operation_key' => (string) Illuminate\Support\Str::uuid(), 'rows' => [$preview['rows'][0]['id']]], ['HTTP_HOST' => $this->host])->assertUnprocessable();
    $this->travel(25)->hours();
    $this->postJson($this->url.'/'.$other['id'].'/confirm', ['operation_key' => (string) Illuminate\Support\Str::uuid(), 'rows' => [$other['rows'][0]['id']]], ['HTTP_HOST' => $this->host])->assertGone();
});

test('partial batch preserves first admission and records capacity failure independently', function (): void {
    $this->event->update(['capacity' => 1]);
    $preview = ($this->preview)("full_name,email\nAda,ada@example.test\nBen,ben@example.test")->json();
    $this->postJson($this->url.'/'.$preview['id'].'/confirm', ['operation_key' => (string) Illuminate\Support\Str::uuid(), 'rows' => array_column($preview['rows'], 'id')], ['HTTP_HOST' => $this->host])->assertOk();
    app(App\Services\Events\AttendeeImport::class)->process($preview['id']);
    $this->getJson($this->url.'/'.$preview['id'], ['HTTP_HOST' => $this->host])->assertOk()->assertJsonPath('counts.created', 1)->assertJsonPath('counts.failed', 1);
    expect(EventRegistration::count())->toBe(1);
});

test('revoked queued actor stops before creating rows and can resume after restored permission', function (): void {
    $preview = ($this->preview)("full_name,email\nAda,ada@example.test")->json();
    $this->postJson($this->url.'/'.$preview['id'].'/confirm', ['operation_key' => (string) Illuminate\Support\Str::uuid(), 'rows' => [$preview['rows'][0]['id']]], ['HTTP_HOST' => $this->host])->assertOk();
    $this->admin->removeRole('Org Admin');
    app(App\Services\Events\AttendeeImport::class)->process($preview['id']);
    expect(EventRegistration::count())->toBe(0)->and(App\Models\EventAttendeeImport::find($preview['id'])->status)->toBe('stopped');
});

test('existing and cancelled identities are never overwritten or reactivated', function (): void {
    $existing = EventRegistration::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'email' => 'ada@example.test', 'full_name' => 'Original']);
    ($this->preview)("full_name,email\nChanged,ADA@example.test")->assertOk()->assertJsonPath('rows.0.status', 'existing');
    $existing->update(['status' => 'cancelled']);
    ($this->preview)("full_name,email\nChanged,ada@example.test")->assertOk()->assertJsonPath('rows.0.status', 'conflict');
    expect($existing->fresh()->full_name)->toBe('Original')->and($existing->fresh()->status)->toBe('cancelled');
});

test('ticket mapping stays event scoped and requires review for ambiguous labels', function (): void {
    App\Models\EventTicketType::factory()->count(2)->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'name' => 'General']);
    ($this->preview)("full_name,email,ticket_type\nAda,ada@example.test,General")->assertOk()->assertJsonPath('rows.0.status', 'error');
    $foreign = App\Models\EventTicketType::factory()->create();
    ($this->preview)("full_name,email,ticket_type\nAda,ada@example.test,General", ['ticket_mapping' => ['General' => $foreign->id]])->assertOk()->assertJsonPath('rows.0.status', 'error');
});

test('non-finance staff cannot declare external money', function (): void {
    $staff = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($staff->id);
    $staff->givePermissionTo('update event');
    $this->actingAs($staff);
    ($this->preview)("full_name,email,payment_status\nAda,ada@example.test,paid_elsewhere")->assertForbidden();
});

test('template is private CSV containing only supported import fields', function (): void {
    $this->get($this->url.'/template', ['HTTP_HOST' => $this->host])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('full_name,email,phone,ticket_type,external_reference,payment_status,external_amount,currency');
});

test('invitations require publication explicit selected recipients and replay freezes selection', function (): void {
    $preview = ($this->preview)("full_name,email\nAda,ada@example.test\nBen,ben@example.test")->json();
    $this->postJson($this->url.'/'.$preview['id'].'/confirm', ['operation_key' => (string) Illuminate\Support\Str::uuid(), 'rows' => array_column($preview['rows'], 'id')], ['HTTP_HOST' => $this->host])->assertOk();
    app(App\Services\Events\AttendeeImport::class)->process($preview['id']);
    $registrations = EventRegistration::orderBy('email')->get();
    $body = ['operation_key' => (string) Illuminate\Support\Str::uuid(), 'registrations' => [$registrations[0]->id], 'revisions' => ($this->invitationRevisions)($preview['id'])];
    $this->postJson($this->url.'/'.$preview['id'].'/invite', $body, ['HTTP_HOST' => $this->host])->assertConflict();
    $this->event->update(['status' => 'published']);
    $this->postJson($this->url.'/'.$preview['id'].'/invite', $body, ['HTTP_HOST' => $this->host])->assertOk()->assertJsonPath('invitations.pending', 1);
    $this->postJson($this->url.'/'.$preview['id'].'/invite', $body, ['HTTP_HOST' => $this->host])->assertOk();
    $this->postJson($this->url.'/'.$preview['id'].'/invite', [...$body, 'registrations' => [$registrations[1]->id]], ['HTTP_HOST' => $this->host])->assertConflict();
    $invitation = App\Models\EventAttendeeImportInvitation::firstOrFail();
    app(App\Services\Events\AttendeeImportInvitations::class)->deliver($invitation->id);
    app(App\Services\Events\AttendeeImportInvitations::class)->deliver($invitation->id);
    Mail::assertSent(App\Mail\Events\EventRegistrationVerifyEmail::class, fn ($mail): bool => $mail->hasTo('ada@example.test'));
    Mail::assertSentCount(1);
    expect($invitation->fresh()->status)->toBe('sent');
});

test('invitation worker stops after email change without mailing the old or new address', function (): void {
    $this->event->update(['status' => 'published']);
    $preview = ($this->preview)("full_name,email\nAda,ada@example.test")->json();
    $this->postJson($this->url.'/'.$preview['id'].'/confirm', ['operation_key' => (string) Illuminate\Support\Str::uuid(), 'rows' => [$preview['rows'][0]['id']]], ['HTTP_HOST' => $this->host])->assertOk();
    app(App\Services\Events\AttendeeImport::class)->process($preview['id']);
    $registration = EventRegistration::firstOrFail();
    $this->postJson($this->url.'/'.$preview['id'].'/invite', ['operation_key' => (string) Illuminate\Support\Str::uuid(), 'registrations' => [$registration->id], 'revisions' => ($this->invitationRevisions)($preview['id'])], ['HTTP_HOST' => $this->host])->assertOk();
    $registration->update(['email' => 'other@example.test']);
    $invitation = App\Models\EventAttendeeImportInvitation::firstOrFail();
    app(App\Services\Events\AttendeeImportInvitations::class)->deliver($invitation->id);
    Mail::assertNothingSent();
    expect($invitation->fresh()->status)->toBe('cancelled');
});

test('retention removes transient personal rows but keeps admission and external financial provenance', function (): void {
    $preview = ($this->preview)("full_name,email\nAda,ada@example.test")->json();
    $this->postJson($this->url.'/'.$preview['id'].'/confirm', ['operation_key' => (string) Illuminate\Support\Str::uuid(), 'rows' => [$preview['rows'][0]['id']]], ['HTTP_HOST' => $this->host])->assertOk();
    app(App\Services\Events\AttendeeImport::class)->process($preview['id']);
    $this->travel(31)->days();
    app(App\Services\Events\AttendeeImport::class)->purge();
    expect(App\Models\EventAttendeeImportRow::first()->data)->toBeNull()->and(EventRegistration::first()->email)->toBe('ada@example.test');
});

test('native new admission respects combined quota usage across legacy counter rows', function (): void {
    $this->event->update(['status' => 'published']);
    App\Models\TenantFeature::create(['tenant_id' => $this->tenant->id, 'feature_key' => 'event_registrations', 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => 2]]);
    App\Models\TenantFeatureUsage::create(['tenant_id' => $this->tenant->id, 'feature_slug' => 'event_registrations', 'used_count' => 1]);
    App\Models\TenantFeatureUsage::create(['tenant_id' => $this->tenant->id, 'feature_slug' => 'event_registrations', 'used_count' => 1]);
    $this->post("http://{$this->host}/e/{$this->event->slug}/register", ['full_name' => 'Native', 'email' => 'native@example.test'], ['HTTP_HOST' => $this->host])->assertRedirect();
    expect(EventRegistration::count())->toBe(0);
});

test('imported signed access rejects a previous email and remains transactional unmetered', function (): void {
    $this->event->update(['status' => 'published']);
    $preview = ($this->preview)("full_name,email\nAda,ada@example.test")->json();
    $this->postJson($this->url.'/'.$preview['id'].'/confirm', ['operation_key' => (string) Illuminate\Support\Str::uuid(), 'rows' => [$preview['rows'][0]['id']]], ['HTTP_HOST' => $this->host])->assertOk();
    app(App\Services\Events\AttendeeImport::class)->process($preview['id']);
    $registration = EventRegistration::firstOrFail();
    $mailable = new App\Mail\Events\EventRegistrationVerifyEmail($registration);
    $link = $mailable->content()->with['verifyUrl'];
    $registration->update(['email' => 'new@example.test']);
    $this->get($link, ['HTTP_HOST' => $this->host])->assertForbidden();
    $registration->refresh();
    $link = (new App\Mail\Events\EventRegistrationVerifyEmail($registration))->content()->with['verifyUrl'];
    $this->get($link, ['HTTP_HOST' => $this->host])->assertRedirect()->assertSessionMissing(App\Services\Events\PlatformAttendeeVerification::SESSION_KEY);
    expect($registration->fresh()->email_verified_at)->not->toBeNull();
    expect(App\Models\TenantFeatureUsage::where('tenant_id', $this->tenant->id)->where('feature_slug', 'email_credits')->sum('used_count'))->toBe(0);
});

test('guest and buyer views label external payment separately without exposing original references', function (): void {
    $this->event->update(['status' => 'published']);
    $preview = ($this->preview)("full_name,email,payment_status,external_amount,currency,external_reference\nAda,ada@example.test,paid_elsewhere,12.34,GHS,private-source")->json();
    $this->postJson($this->url.'/'.$preview['id'].'/confirm', ['operation_key' => (string) Illuminate\Support\Str::uuid(), 'rows' => [$preview['rows'][0]['id']]], ['HTTP_HOST' => $this->host])->assertOk();
    app(App\Services\Events\AttendeeImport::class)->process($preview['id']);
    $this->get("http://{$this->host}/events/{$this->event->id}/guests", ['HTTP_HOST' => $this->host])->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page->where('registrations.0.admission_payment_label', 'Paid elsewhere')->where('registrations.0.external_amount', 1234));
});

test('independent verified email opens imported app ticket without manufacturing persistent email proof', function (): void {
    $this->event->update(['status' => 'published']);
    $preview = ($this->preview)("full_name,email,payment_status\nAda,ada@example.test,paid_elsewhere")->json();
    $this->postJson($this->url.'/'.$preview['id'].'/confirm', ['operation_key' => (string) Illuminate\Support\Str::uuid(), 'rows' => [$preview['rows'][0]['id']]], ['HTTP_HOST' => $this->host]);
    app(App\Services\Events\AttendeeImport::class)->process($preview['id']);
    $registration = EventRegistration::firstOrFail();
    $session = [App\Services\Events\PlatformAttendeeVerification::SESSION_KEY => ['email' => 'ada@example.test', 'verified_at' => now()->timestamp, 'expires_at' => now()->addHours(12)->timestamp]];
    $this->withSession($session)->getJson("http://miconvener.test/my/app/tickets/{$registration->id}", ['HTTP_HOST' => 'miconvener.test'])->assertOk()->assertJsonPath('registration.admission_payment_label', 'Paid elsewhere')->assertJsonMissingPath('registration.external_reference');
    expect($registration->fresh()->email_verified_at)->toBeNull();
});

test('editing imported email rotates credentials resets ownership and sends no implicit invitation', function (): void {
    $preview = ($this->preview)("full_name,email\nAda,ada@example.test")->json();
    $this->postJson($this->url.'/'.$preview['id'].'/confirm', ['operation_key' => (string) Illuminate\Support\Str::uuid(), 'rows' => [$preview['rows'][0]['id']]], ['HTTP_HOST' => $this->host]);
    app(App\Services\Events\AttendeeImport::class)->process($preview['id']);
    $registration = EventRegistration::firstOrFail();
    $ticket = $registration->ticket_code;
    $registration->update(['email_verified_at' => now()]);
    $this->patchJson("http://{$this->host}/events/{$this->event->id}/registrations/{$registration->id}", ['full_name' => 'Ada', 'email' => 'new@example.test'], ['HTTP_HOST' => $this->host])->assertOk();
    expect($registration->fresh()->email_verified_at)->toBeNull()->and($registration->fresh()->ticket_code)->not->toBe($ticket)->and($registration->fresh()->admission_payment_source)->toBe('free');
    Mail::assertNothingQueued();
});

test('cancelled external admission cannot be revived by a native payment confirmer', function (): void {
    $registration = EventRegistration::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'status' => 'cancelled', 'registration_source' => 'external_import', 'admission_payment_source' => 'paid_elsewhere', 'external_amount' => 1234, 'external_currency' => 'GHS']);
    app(App\Services\Events\StandardEventPaymentConfirmer::class)->confirm($registration, 'unrelated', 1234, 20, 'GHS', 'paystack', true);
    expect($registration->fresh()->status)->toBe('cancelled')->and($registration->fresh()->amount)->toBe(0)->and(App\Models\EventLedgerEntry::count())->toBe(0);
});

test('CSV preserves multibyte attendee names and quoted multiline text exactly', function (): void {
    ($this->preview)("full_name,email\n\"Ési 漢字\",esi@example.test")->assertOk()->assertJsonPath('rows.0.data.full_name', 'Ési 漢字')->assertJsonPath('rows.0.data.email', 'esi@example.test');
});

test('mixed imported admission native ticket and supported contribution retain independent money histories', function (): void {
    $this->event->update(['status' => 'published', 'allow_contributions' => true, 'capacity' => 3]);
    $preview = ($this->preview)("full_name,email,payment_status,external_amount,currency\nAda,ada@example.test,paid_elsewhere,12.34,GHS")->json();
    $this->postJson($this->url.'/'.$preview['id'].'/confirm', ['operation_key' => (string) Illuminate\Support\Str::uuid(), 'rows' => [$preview['rows'][0]['id']]], ['HTTP_HOST' => $this->host]);
    app(App\Services\Events\AttendeeImport::class)->process($preview['id']);
    $imported = EventRegistration::firstOrFail();
    $native = EventRegistration::factory()->pendingPayment()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'email' => 'native@example.test', 'amount' => 5000]);
    app(App\Services\Events\StandardEventPaymentConfirmer::class)->confirm($native, 'NATIVE-ONLY-1', 5000, 100, 'GHS', 'paystack', true);
    $contribution = App\Models\EventContribution::create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'contributor_name' => 'Ada', 'contributor_email' => $imported->email, 'amount' => 2000, 'currency' => 'GHS', 'status' => 'pending', 'payment_reference' => 'CONTRIB-ONLY-1', 'provider' => 'paystack']);
    app(App\Services\Events\EventContributionService::class)->confirmContributionPayment('CONTRIB-ONLY-1', ['amount' => 2000, 'fees' => 40, 'currency' => 'GHS']);
    expect($imported->fresh()->external_amount)->toBe(1234)->and($imported->fresh()->amount)->toBe(0)->and($contribution->fresh()->status)->toBe('completed')->and($native->fresh()->amount)->toBe(5000);
    $this->getJson("http://{$this->host}/events/{$this->event->id}/data/reports", ['HTTP_HOST' => $this->host])->assertOk()->assertJsonPath('payment_summary.native.0.amount', 5000)->assertJsonPath('payment_summary.external.0.amount', 1234)->assertJsonPath('payment_summary.contributions.0.amount', 2000);
});

test('result export escapes spreadsheet formulas and never exposes ticket credentials', function (): void {
    $preview = ($this->preview)("full_name,email\n=1+1,ada@example.test")->json();
    $response = $this->get($this->url.'/'.$preview['id'].'/export', ['HTTP_HOST' => $this->host])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect($response->streamedContent())->toContain("'=1+1")->not->toContain('ticket_code')->not->toContain('qr_token');
});

test('imported admission cannot start native gateway checkout after cancellation', function (): void {
    $this->event->update(['status' => 'published']);
    $registration = EventRegistration::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'status' => 'cancelled', 'registration_source' => 'external_import']);
    Illuminate\Support\Facades\Http::preventStrayRequests();
    $this->postJson("http://{$this->host}/e/{$this->event->slug}/checkout/{$registration->id}/paystack", [], ['HTTP_HOST' => $this->host])->assertUnprocessable();
});

test('uncertain invitation transport is retained and never replayed automatically', function (): void {
    $this->event->update(['status' => 'published']);
    $preview = ($this->preview)("full_name,email\nAda,ada@example.test")->json();
    $this->postJson($this->url.'/'.$preview['id'].'/confirm', ['operation_key' => (string) Illuminate\Support\Str::uuid(), 'rows' => [$preview['rows'][0]['id']]], ['HTTP_HOST' => $this->host]);
    app(App\Services\Events\AttendeeImport::class)->process($preview['id']);
    $registration = EventRegistration::first();
    $this->postJson($this->url.'/'.$preview['id'].'/invite', ['operation_key' => (string) Illuminate\Support\Str::uuid(), 'registrations' => [$registration->id], 'revisions' => ($this->invitationRevisions)($preview['id'])], ['HTTP_HOST' => $this->host]);
    $invitation = App\Models\EventAttendeeImportInvitation::first();
    Mail::shouldReceive('to')->once()->andReturnSelf();
    Mail::shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP outcome unknown'));
    app(App\Services\Events\AttendeeImportInvitations::class)->deliver($invitation->id);
    app(App\Services\Events\AttendeeImportInvitations::class)->deliver($invitation->id);
    expect($invitation->fresh()->status)->toBe('uncertain');
});

test('dispatch interruption retains frozen confirmation and resumes without another admission', function (): void {
    $preview = ($this->preview)("full_name,email\nAda,ada@example.test")->json();
    $originalDispatcher = app(Illuminate\Contracts\Bus\Dispatcher::class);
    $dispatcher = Mockery::mock(Illuminate\Contracts\Bus\Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->andThrow(new RuntimeException('Queue unavailable'));
    app()->instance(Illuminate\Contracts\Bus\Dispatcher::class, $dispatcher);
    $body = ['operation_key' => (string) Illuminate\Support\Str::uuid(), 'rows' => [$preview['rows'][0]['id']]];
    $this->postJson($this->url.'/'.$preview['id'].'/confirm', $body, ['HTTP_HOST' => $this->host])->assertOk()->assertJsonPath('status', 'stopped');
    expect(App\Models\EventAttendeeImport::find($preview['id'])->selection_digest)->not->toBeNull();
    app()->instance(Illuminate\Contracts\Bus\Dispatcher::class, $originalDispatcher);
    $this->postJson($this->url.'/'.$preview['id'].'/resume', [], ['HTTP_HOST' => $this->host])->assertOk();
    Queue::assertPushed(App\Jobs\Events\ProcessAttendeeImport::class, fn ($job): bool => $job->importId === $preview['id']);
    app(App\Services\Events\AttendeeImport::class)->process($preview['id']);
    app(App\Services\Events\AttendeeImport::class)->process($preview['id']);
    expect(EventRegistration::count())->toBe(1);
});

test('confirmation rejects ended events and operation keys reused for another preview', function (): void {
    $first = ($this->preview)("full_name,email\nAda,ada@example.test")->json();
    $second = ($this->preview)("full_name,email\nBen,ben@example.test")->json();
    $key = (string) Illuminate\Support\Str::uuid();
    $this->postJson($this->url.'/'.$first['id'].'/confirm', ['operation_key' => $key, 'rows' => [$first['rows'][0]['id']]], ['HTTP_HOST' => $this->host])->assertOk();
    $this->postJson($this->url.'/'.$second['id'].'/confirm', ['operation_key' => $key, 'rows' => [$second['rows'][0]['id']]], ['HTTP_HOST' => $this->host])->assertConflict();
    $this->event->update(['ends_at' => now()->subHour()]);
    $this->postJson($this->url.'/'.$second['id'].'/confirm', ['operation_key' => (string) Illuminate\Support\Str::uuid(), 'rows' => [$second['rows'][0]['id']]], ['HTTP_HOST' => $this->host])->assertConflict();
});

test('queued imports stop when the organisation is deactivated', function (): void {
    $preview = ($this->preview)("full_name,email\nAda,ada@example.test")->json();
    $this->postJson($this->url.'/'.$preview['id'].'/confirm', ['operation_key' => (string) Illuminate\Support\Str::uuid(), 'rows' => [$preview['rows'][0]['id']]], ['HTTP_HOST' => $this->host]);
    $this->tenant->update(['status' => App\Enum\TenantStatusEnum::DEACTIVATED]);
    app(App\Services\Events\AttendeeImport::class)->process($preview['id']);
    expect(EventRegistration::count())->toBe(0)->and(App\Models\EventAttendeeImport::find($preview['id'])->status)->toBe('stopped');
});

test('all guest exports escape imported formula names while keeping integer money numeric', function (): void {
    EventRegistration::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'full_name' => '=1+1', 'registration_source' => 'external_import', 'admission_payment_source' => 'paid_elsewhere', 'external_amount' => 1234, 'external_currency' => 'GHS']);
    foreach (["http://{$this->host}/events/{$this->event->id}/guests/export", "http://{$this->host}/events/{$this->event->id}/reports/registrations"] as $url) {
        $response = $this->get($url, ['HTTP_HOST' => $this->host])->assertOk();
        expect($response->streamedContent())->toContain("'=1+1");
    }
});

test('invitation selection rejects recipient changes after staff review', function (): void {
    $this->event->update(['status' => 'published']);
    $preview = ($this->preview)("full_name,email\nAda,ada@example.test")->json();
    $this->postJson($this->url.'/'.$preview['id'].'/confirm', ['operation_key' => (string) Illuminate\Support\Str::uuid(), 'rows' => [$preview['rows'][0]['id']]], ['HTTP_HOST' => $this->host]);
    app(App\Services\Events\AttendeeImport::class)->process($preview['id']);
    $registration = EventRegistration::firstOrFail();
    $view = $this->getJson($this->url.'/'.$preview['id'], ['HTTP_HOST' => $this->host])->assertOk()->assertJsonPath('rows.0.invitation_recipient', 'ada@example.test')->json();
    $registration->update(['email' => 'changed@example.test']);
    $body = ['operation_key' => (string) Illuminate\Support\Str::uuid(), 'registrations' => [$registration->id], 'revisions' => [$registration->id => $view['rows'][0]['invitation_revision']]];
    $this->postJson($this->url.'/'.$preview['id'].'/invite', $body, ['HTTP_HOST' => $this->host])->assertConflict();
    expect(App\Models\EventAttendeeImportInvitation::count())->toBe(0);
    $this->getJson($this->url.'/'.$preview['id'], ['HTTP_HOST' => $this->host])->assertOk()->assertJsonPath('rows.0.invitation_recipient', 'changed@example.test');
});

test('recent imports and explicit resume remain scoped to the initiating staff member', function (): void {
    $preview = ($this->preview)("full_name,email\nAda,ada@example.test")->json();
    $this->postJson($this->url.'/'.$preview['id'].'/confirm', ['operation_key' => (string) Illuminate\Support\Str::uuid(), 'rows' => [$preview['rows'][0]['id']]], ['HTTP_HOST' => $this->host])->assertOk();
    $this->getJson($this->url, ['HTTP_HOST' => $this->host])->assertOk()->assertJsonPath('imports.0.id', $preview['id']);
    $otherEvent = Event::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->getJson("http://{$this->host}/events/{$otherEvent->id}/attendee-imports", ['HTTP_HOST' => $this->host])->assertOk()->assertJsonCount(0, 'imports');
    $foreign = Event::factory()->create();
    $this->getJson("http://{$this->host}/events/{$foreign->id}/attendee-imports", ['HTTP_HOST' => $this->host])->assertNotFound();
    $other = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($other->id);
    $other->assignRole('Org Admin');
    $this->actingAs($other);
    $this->getJson($this->url, ['HTTP_HOST' => $this->host])->assertOk()->assertJsonCount(0, 'imports');
    $this->postJson($this->url.'/'.$preview['id'].'/resume', [], ['HTTP_HOST' => $this->host])->assertNotFound();
});

test('sweepers recover untouched admission and invitations while interrupted email stays uncertain', function (): void {
    $this->event->update(['status' => 'published']);
    $preview = ($this->preview)("full_name,email\nAda,ada@example.test\nBen,ben@example.test")->json();
    $operation = (string) Illuminate\Support\Str::uuid();
    $this->postJson($this->url.'/'.$preview['id'].'/confirm', ['operation_key' => $operation, 'rows' => array_column($preview['rows'], 'id')], ['HTTP_HOST' => $this->host])->assertOk();
    App\Models\EventAttendeeImport::whereKey($preview['id'])->update(['updated_at' => now()->subMinutes(10)]);
    Queue::fake();
    app(App\Services\Events\AttendeeImport::class)->recover();
    expect(EventRegistration::count())->toBe(0);
    Queue::assertPushed(App\Jobs\Events\ProcessAttendeeImport::class, fn ($job): bool => $job->importId === $preview['id']);
    expect(App\Models\EventAttendeeImport::find($preview['id'])->operation_key)->toBe($operation);
    app(App\Services\Events\AttendeeImport::class)->process($preview['id']);
    $registration = EventRegistration::firstOrFail();
    $this->postJson($this->url.'/'.$preview['id'].'/invite', ['operation_key' => (string) Illuminate\Support\Str::uuid(), 'registrations' => EventRegistration::pluck('id')->all(), 'revisions' => ($this->invitationRevisions)($preview['id'])], ['HTTP_HOST' => $this->host])->assertOk();
    $this->postJson($this->url.'/'.$preview['id'].'/retry-invitations', [], ['HTTP_HOST' => $this->host])->assertOk();
    $invitation = App\Models\EventAttendeeImportInvitation::firstOrFail();
    Queue::assertPushed(App\Jobs\Events\DeliverAttendeeImportInvitation::class, fn ($job): bool => $job->invitationId === $invitation->id);
    $invitation->update(['status' => 'sending', 'claimed_at' => now()->subMinutes(10)]);
    Queue::fake();
    app(App\Services\Events\AttendeeImportInvitations::class)->recover();
    expect($invitation->fresh()->status)->toBe('uncertain');
    $untouched = App\Models\EventAttendeeImportInvitation::where('id', '!=', $invitation->id)->firstOrFail();
    Queue::assertPushed(App\Jobs\Events\DeliverAttendeeImportInvitation::class, 1);
    Queue::assertPushed(App\Jobs\Events\DeliverAttendeeImportInvitation::class, fn ($job): bool => $job->invitationId === $untouched->id);
    $untouched->update(['status' => 'failed']);
    Queue::fake();
    app(App\Services\Events\AttendeeImportInvitations::class)->recover();
    Queue::assertPushed(App\Jobs\Events\DeliverAttendeeImportInvitation::class, 1);
    Queue::assertPushed(App\Jobs\Events\DeliverAttendeeImportInvitation::class, fn ($job): bool => $job->invitationId === $untouched->id);
    app(App\Services\Events\AttendeeImportInvitations::class)->deliver($invitation->id);
    Mail::assertNothingSent();
});

test('fresh global superadmin authority works without a tenant pivot and revocation stops queued work', function (): void {
    $this->event->update(['status' => 'published']);
    $global = User::factory()->create(['tenant_id' => null]);
    setPermissionsTeamId(null);
    $global->assignRole('Superadmin');
    setPermissionsTeamId($this->tenant->id);
    $this->actingAs($global);
    $first = ($this->preview)("full_name,email\nFirst,first@example.test")->assertOk()->json();
    $this->postJson($this->url.'/'.$first['id'].'/confirm', ['operation_key' => (string) Illuminate\Support\Str::uuid(), 'rows' => [$first['rows'][0]['id']]], ['HTTP_HOST' => $this->host])->assertOk();
    app(App\Services\Events\AttendeeImport::class)->process($first['id']);
    expect(EventRegistration::where('email', 'first@example.test')->exists())->toBeTrue();
    $registration = EventRegistration::where('email', 'first@example.test')->firstOrFail();
    $this->postJson($this->url.'/'.$first['id'].'/invite', ['operation_key' => (string) Illuminate\Support\Str::uuid(), 'registrations' => [$registration->id], 'revisions' => ($this->invitationRevisions)($first['id'])], ['HTTP_HOST' => $this->host])->assertOk();
    $second = ($this->preview)("full_name,email\nSecond,second@example.test")->assertOk()->json();
    $this->postJson($this->url.'/'.$second['id'].'/confirm', ['operation_key' => (string) Illuminate\Support\Str::uuid(), 'rows' => [$second['rows'][0]['id']]], ['HTTP_HOST' => $this->host])->assertOk();
    setPermissionsTeamId(null);
    $global->removeRole('Superadmin');
    setPermissionsTeamId($this->tenant->id);
    app(App\Services\Events\AttendeeImport::class)->process($second['id']);
    expect(EventRegistration::where('email', 'second@example.test')->exists())->toBeFalse()->and(App\Models\EventAttendeeImport::find($second['id'])->status)->toBe('stopped');
    $invitation = App\Models\EventAttendeeImportInvitation::firstOrFail();
    app(App\Services\Events\AttendeeImportInvitations::class)->deliver($invitation->id);
    expect($invitation->fresh()->status)->toBe('failed');
    Mail::assertNothingSent();
});
