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
