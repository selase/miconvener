<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventForumThread;
use App\Models\EventPoll;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use ZipArchive;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function reportHost(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

test('the reports summary reflects real counts', function () {
    [$tenant, $user] = reportHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_CONFIRMED]);
    EventRegistration::factory()->checkedIn()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    EventForumThread::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->getJson("http://{$host}/events/{$event->id}/reports", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertJsonPath('counts.registrations', 2); // confirmed + checked_in both count as confirmed
    $response->assertJsonPath('counts.checked_in', 1);
    $response->assertJsonPath('counts.forum_threads', 1);
    expect($response->json('available_columns'))->toHaveCount(12);
});

test('the registrations export only includes the requested columns, in a fixed order', function () {
    [$tenant, $user] = reportHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'full_name' => 'Kwame Asante', 'email' => 'kwame@example.com']);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->get("http://{$host}/events/{$event->id}/reports/registrations?columns[]=email&columns[]=name", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $lines = explode("\n", trim($response->streamedContent()));
    expect($lines[0])->toBe('Name,Email'); // canonical column order, not request order
    expect($lines[1])->toContain('Kwame Asante');
    expect($lines[1])->toContain('kwame@example.com');
});

test('the check-in log export only includes checked-in registrations', function () {
    [$tenant, $user] = reportHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    EventRegistration::factory()->checkedIn()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'full_name' => 'Checked In Person']);
    EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'full_name' => 'Not Checked In']);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->get("http://{$host}/events/{$event->id}/reports/checkins", ['HTTP_HOST' => $host]);

    $content = $response->streamedContent();
    expect($content)->toContain('Checked In Person');
    expect($content)->not->toContain('Not Checked In');
});

test('the poll results export includes one row per response with the resolved answer', function () {
    [$tenant, $user] = reportHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $poll = EventPoll::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'question' => 'Favourite resource?']);
    $option = $poll->options()->create(['tenant_id' => $tenant->id, 'label' => 'AllergyIntolerance']);
    $poll->responses()->create(['tenant_id' => $tenant->id, 'option_id' => $option->id, 'respondent_token' => 'token-1']);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->get("http://{$host}/events/{$event->id}/reports/polls", ['HTTP_HOST' => $host]);

    $content = $response->streamedContent();
    expect($content)->toContain('Favourite resource?');
    expect($content)->toContain('AllergyIntolerance');
});

test('the attendee directory dedupes by email across every event the tenant has run', function () {
    [$tenant, $user] = reportHost();
    $eventA = Event::factory()->create(['tenant_id' => $tenant->id]);
    $eventB = Event::factory()->create(['tenant_id' => $tenant->id]);
    EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $eventA->id, 'full_name' => 'Repeat Attendee', 'email' => 'repeat@example.com']);
    EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $eventB->id, 'full_name' => 'Repeat Attendee', 'email' => 'repeat@example.com']);
    EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $eventA->id, 'full_name' => 'Once Only', 'email' => 'once@example.com']);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->get("http://{$host}/events/{$eventA->id}/reports/attendee-directory", ['HTTP_HOST' => $host]);

    $rows = collect(explode("\n", trim($response->streamedContent())))
        ->skip(1)
        ->filter()
        ->map(fn ($line) => str_getcsv($line))
        ->keyBy(fn ($row) => $row[1]); // email column

    expect((int) $rows['repeat@example.com'][3])->toBe(2); // events registered for
    expect((int) $rows['once@example.com'][3])->toBe(1);
});

test('the session attendance export lists everyone who added a session to their day', function () {
    [$tenant, $user] = reportHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $session = EventSession::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'title' => 'Opening keynote']);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'full_name' => 'Attendee One']);
    $registration->sessions()->attach($session->id, ['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->get("http://{$host}/events/{$event->id}/reports/session-attendance", ['HTTP_HOST' => $host]);

    $content = $response->streamedContent();
    expect($content)->toContain('Opening keynote');
    expect($content)->toContain('Attendee One');
});

test('the dietary and accessibility summary groups values case-insensitively with a not-specified bucket', function () {
    [$tenant, $user] = reportHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_CONFIRMED, 'dietary_requirements' => 'Vegetarian']);
    EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_CONFIRMED, 'dietary_requirements' => 'vegetarian']);
    EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_CONFIRMED, 'dietary_requirements' => null]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->get("http://{$host}/events/{$event->id}/reports/dietary-accessibility", ['HTTP_HOST' => $host]);

    $content = $response->streamedContent();
    expect($content)->toContain('vegetarian,2');
    expect($content)->toContain('"not specified",1');
});

test('the audit log export shows changes made to a registration, excluding the qr token', function () {
    [$tenant, $user] = reportHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_PENDING_APPROVAL]);
    $registration->update(['status' => EventRegistration::STATUS_CONFIRMED]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->get("http://{$host}/events/{$event->id}/reports/audit-log", ['HTTP_HOST' => $host]);

    $content = $response->streamedContent();
    expect($content)->toContain('EventRegistration #'.$registration->id);
    expect($content)->not->toContain('qr_token');

    $activity = Activity::where('subject_id', $registration->id)->firstOrFail();
    expect($activity->properties->toArray())->not->toHaveKey('qr_token');
});

test('certificates are generated only for checked-in attendees, bundled as a zip', function () {
    [$tenant, $user] = reportHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Annual Summit']);
    EventRegistration::factory()->checkedIn()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'full_name' => 'Certified Attendee']);
    EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'full_name' => 'Not Checked In']);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->get("http://{$host}/events/{$event->id}/reports/certificates", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $zipPath = $response->getFile()->getRealPath();
    $zip = new ZipArchive();
    $zip->open($zipPath);
    expect($zip->numFiles)->toBe(1);
    expect($zip->getNameIndex(0))->toContain('certified-attendee');
    $zip->close();
});

test('certificates cannot be generated when nobody has checked in', function () {
    [$tenant, $user] = reportHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->get("http://{$host}/events/{$event->id}/reports/certificates", ['HTTP_HOST' => $host])
        ->assertNotFound();
});

test('a host without update event permission cannot export registrations', function () {
    [$tenant] = reportHost();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $tenant->users()->attach($user->id); // no role assigned

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->get("http://{$host}/events/{$event->id}/reports/registrations", ['HTTP_HOST' => $host])
        ->assertForbidden();
});
