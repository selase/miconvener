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
    $this->preview = fn (string $csv, array $extra = []) => $this->postJson($this->url.'/preview', ['file' => UploadedFile::fake()->createWithContent('attendees.csv', $csv), ...$extra], ['HTTP_HOST' => $this->host]);
});

test('private CSV preview freezes canonical rows with strict declared money and no admission or mail', function (): void {
    $response = ($this->preview)("\xEF\xBB\xBFfull_name,email,payment_status,external_amount,currency\r\n\"Ada, One\",ADA@example.test,paid_elsewhere,12.34,GHS\r\n");
    $response->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('rows.0.data.email', 'ada@example.test')->assertJsonPath('rows.0.data.external_amount', 1234)->assertJsonPath('rows.0.status', 'valid');
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

