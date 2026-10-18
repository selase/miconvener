<?php

declare(strict_types=1);

use App\Models\CommunityLibraryItem;
use App\Models\CommunitySettings;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Services\Community\CommunityContentAccess;
use App\Services\Events\PlatformAttendeeVerification;

require_once __DIR__.'/../../Support/CommunityLibraryScenario.php';

/** @return array{App\Models\Tenant,App\Models\Member,Event,EventSession,CommunityLibraryItem} */
function serviceReader(): array
{
    [$tenant, $member] = libraryReader();
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'is_recurring' => true, 'status' => 'published']);
    $session = EventSession::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'is_occurrence' => true, 'occurrence_status' => 'scheduled', 'title' => 'Morning service', 'starts_at' => '2026-11-01 05:30:00', 'ends_at' => '2026-11-01 06:15:00']);
    $note = CommunityLibraryItem::factory()->create(['tenant_id' => $tenant->id, 'kind' => 'service_note', 'event_id' => $event->id, 'session_id' => $session->id, 'title' => 'Hope and service', 'plain_text' => 'Readings and sermon notes.']);

    return [$tenant, $member, $event, $session, $note];
}

it('lists distinct dated services and opens notes without any attendee registration', function () {
    [$tenant, $member, $event, $session, $note] = serviceReader();
    $settings = CommunitySettings::for($tenant);
    $settings->fill(['timezone' => 'America/New_York'])->save();
    $second = EventSession::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'is_occurrence' => true, 'occurrence_status' => 'scheduled', 'title' => 'Evening service', 'starts_at' => '2026-11-01 06:30:00', 'ends_at' => '2026-11-01 07:30:00']);
    CommunityLibraryItem::factory()->create(['tenant_id' => $tenant->id, 'kind' => 'service_note', 'event_id' => $event->id, 'session_id' => $second->id]);
    $this->withSession([PlatformAttendeeVerification::SESSION_KEY => ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]])
        ->getJson(route('attendee.my.services.index', ['tenantSlug' => $tenant->slug]))->assertOk()->assertJsonCount(2, 'services.data');
    $this->getJson(route('attendee.my.services.show', ['tenantSlug' => $tenant->slug, 'session' => $session->id]))->assertOk()
        ->assertJsonPath('service.id', $session->id)->assertJsonPath('service.starts_at', '2026-11-01T01:30:00-04:00')->assertJsonPath('notes.0.id', $note->id);
    expect(EventRegistration::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('rechecks cancellation and publication on direct service-note reads', function (string $change) {
    [, , $event, $session, $note] = serviceReader();
    if ($change === 'cancelled occurrence') {
        $session->update(['occurrence_status' => 'cancelled']);
    } elseif ($change === 'cancelled event') {
        $event->update(['status' => 'cancelled']);
    } elseif ($change === 'draft event') {
        $event->update(['status' => 'draft']);
    } else {
        $note->update(['release_at' => now()->addDay()]);
    }
    expect(app(CommunityContentAccess::class)->canRead(app('session.store'), $note))->toBeFalse();
})->with(['cancelled occurrence', 'cancelled event', 'draft event', 'future notes']);

it('keeps rescheduled and archived published-service notes linked to their occurrence', function () {
    [$tenant, $member, $event, $session, $note] = serviceReader();
    $session->update(['starts_at' => '2026-11-02 09:00:00', 'ends_at' => '2026-11-02 10:00:00']);
    $event->delete();
    expect(app(CommunityContentAccess::class)->canRead(app('session.store'), $note))->toBeTrue();
    $this->withSession([PlatformAttendeeVerification::SESSION_KEY => ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]])
        ->getJson(route('attendee.my.services.show', ['tenantSlug' => $tenant->slug, 'session' => $session->id]))->assertOk()->assertJsonPath('service.id', $session->id);
});

it('validates service context together and cannot publish against a foreign occurrence', function () {
    [$tenant, $user] = libraryStaff();
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'is_recurring' => true]);
    $foreign = EventSession::factory()->create();
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost($tenant->slug)])
        ->postJson(route('tenant.community.service-notes.store', ['subdomain' => $tenant->slug]), ['title' => 'Private notes', 'plain_text' => 'A sermon', 'state' => 'published', 'audience' => ['kind' => 'all_members'], 'event_id' => $event->id, 'session_id' => $foreign->id])
        ->assertUnprocessable()->assertJsonValidationErrors('session_id');
});

it('publishes a note for one occurrence and keeps programme notes separate', function () {
    [$tenant, $user] = libraryStaff();
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'is_recurring' => true]);
    $session = EventSession::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'is_occurrence' => true, 'notes' => 'Private operational notes']);
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost($tenant->slug)])
        ->postJson(route('tenant.community.service-notes.store', ['subdomain' => $tenant->slug]), ['title' => 'Sermon notes', 'plain_text' => 'A published sermon', 'state' => 'published', 'audience' => ['kind' => 'all_members'], 'event_id' => $event->id, 'session_id' => $session->id])
        ->assertCreated()->assertJsonPath('item.kind', 'service_note');
    expect($session->fresh()->notes)->toBe('Private operational notes');
});

it('preserves library history when staff tries to delete an occurrence', function () {
    [$tenant, $user] = libraryStaff();
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'is_recurring' => true]);
    $session = EventSession::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'is_occurrence' => true]);
    CommunityLibraryItem::factory()->create(['tenant_id' => $tenant->id, 'kind' => 'service_note', 'event_id' => $event->id, 'session_id' => $session->id]);
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost($tenant->slug)])
        ->deleteJson(route('tenant.events.sessions.destroy', ['subdomain' => $tenant->slug, 'event' => $event->id, 'session' => $session->id]))->assertStatus(409);
    expect($session->fresh())->not->toBeNull();
});

it('offers only the tenant recurring series and preserves an explicitly preselected occurrence', function () {
    [$tenant, $user] = libraryStaff();
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'is_recurring' => true]);
    $session = EventSession::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'is_occurrence' => true]);
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost($tenant->slug)])
        ->getJson(route('tenant.community.service-notes.options', ['subdomain' => $tenant->slug, 'event' => $event->id, 'session' => $session->id]))
        ->assertOk()->assertJsonPath('selected_occurrence.id', $session->id)->assertJsonCount(1, 'events.data');
});

it('bounds parent-event queries when a church lists several dated services', function () {
    [$tenant,, $event] = serviceReader();
    for ($i = 0; $i < 3; $i++) {
        $occurrence = EventSession::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'is_occurrence' => true]);
        CommunityLibraryItem::factory()->create(['tenant_id' => $tenant->id, 'kind' => 'service_note', 'event_id' => $event->id, 'session_id' => $occurrence->id]);
    }
    $connection = Illuminate\Support\Facades\DB::connection('landlord');
    $connection->enableQueryLog();
    $service = app(App\Services\Community\CommunityServices::class);
    $entries = $service->entries($tenant, app('session.store'), []);
    foreach ($entries as $entry) {
        $service->payload($tenant, $entry);
    }
    $queries = collect($connection->getQueryLog())->filter(fn ($entry) => preg_match('/^select\s+(?:"events"\.)?\* from "events"/i', $entry['query']) === 1);
    $connection->disableQueryLog();
    expect($entries->count())->toBe(4)->and($queries->count())->toBeLessThanOrEqual(1);
});

it('preserves existing event assets when library history blocks permanent purge', function (string $path) {
    [$tenant,$user] = libraryStaff();
    Illuminate\Support\Facades\Storage::fake('public');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'is_recurring' => true, 'hero_image_path' => 'events/hero/history.jpg', 'purge_at' => now()->subHour()]);
    $session = EventSession::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'is_occurrence' => true]);
    CommunityLibraryItem::factory()->create(['tenant_id' => $tenant->id, 'kind' => 'service_note', 'event_id' => $event->id, 'session_id' => $session->id]);
    $material = App\Models\EventMaterial::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'file_path' => 'event-materials/history.pdf']);
    $disk = Illuminate\Support\Facades\Storage::disk('public');
    $disk->put($event->hero_image_path, 'original-hero');
    $disk->put($material->file_path, 'original-deck');
    $event->delete();
    if ($path === 'manual') {
        $host = eventSubdomainHost($tenant->slug);
        $this->actingAs($user)->deleteJson('http://'.$host.'/events/'.$event->id.'/force-purge', ['confirm_name' => $event->name], ['HTTP_HOST' => $host])->assertUnprocessable();
    } elseif ($path === 'scheduled') {
        $this->artisan('events:purge-deleted')->assertSuccessful();
    } else {
        $connection = $event->getConnection();
        $connection->beginTransaction();
        try {
            $event->forceDelete();
            $this->fail('The retained history FK must reject direct purge.');
        } catch (Illuminate\Database\QueryException) {
        } finally {
            $connection->rollBack();
        }
    }
    expect(Event::withTrashed()->find($event->id))->not->toBeNull()->and($disk->get($event->hero_image_path))->toBe('original-hero')->and($disk->get($material->file_path))->toBe('original-deck');
})->with(['manual', 'scheduled', 'direct']);

it('keeps series choices discoverable outside the dated services result page', function () {
    [$tenant,$member,$event] = serviceReader();
    $this->withSession([PlatformAttendeeVerification::SESSION_KEY => ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]])
        ->getJson(route('attendee.my.services.index', ['tenantSlug' => $tenant->slug, 'from' => '2099-01-01', 'event' => $event->id]))
        ->assertOk()->assertJsonCount(0, 'services.data')->assertJsonPath('series.data.0.id', $event->id)->assertJsonPath('selected_series.id', $event->id);
});
