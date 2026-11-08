<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventStaffAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Events\EventStaffScope;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    $this->tenant = Tenant::factory()->create(['slug' => 'organiser-scope', 'isolation_mode' => 'shared']);
    $this->host = eventSubdomainHost($this->tenant->slug);
    $this->headers = ['HTTP_HOST' => $this->host];
    $this->organiser = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($this->organiser->id);
    setPermissionsTeamId($this->tenant->id);
    $this->organiser->assignRole('Event Organiser');
    $this->event = Event::factory()->published()->create(['tenant_id' => $this->tenant->id]);
    $this->other = Event::factory()->published()->create(['tenant_id' => $this->tenant->id]);
    $this->assign = fn () => EventStaffAssignment::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->organiser->id, 'event_id' => $this->event->id]);
});

test('unassigned event organisers have no event access', function (): void {
    expect(EventStaffScope::restrictedEventIds($this->organiser, $this->tenant->id))->toBe([]);
    $this->actingAs($this->organiser)->get("http://{$this->host}/events", $this->headers)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('events', []));
    $this->get("http://{$this->host}/events/{$this->event->id}", $this->headers)->assertNotFound();
});

test('an organiser manages only assigned events by either id or slug and loses access on removal', function (): void {
    ($this->assign)();
    $this->actingAs($this->organiser)->get("http://{$this->host}/events/{$this->event->id}", $this->headers)->assertOk();
    $this->get("http://{$this->host}/events/{$this->other->id}/check-in", $this->headers)->assertNotFound();
    $this->get("http://{$this->host}/events/{$this->other->slug}", $this->headers)->assertNotFound();
    $this->get("http://{$this->host}/events", $this->headers)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('events', fn ($events): bool => collect($events)->pluck('id')->all() === [$this->event->id]));
    EventStaffAssignment::query()->where('user_id', $this->organiser->id)->delete();
    $this->get("http://{$this->host}/events/{$this->event->id}", $this->headers)->assertNotFound();
});

test('organiser dashboard redirects to their scoped events and organisation routes stay forbidden', function (): void {
    ($this->assign)();
    $this->actingAs($this->organiser)->get("http://{$this->host}/dashboard", $this->headers)->assertRedirect();
    foreach (['users', 'roles', 'settings', 'billing', 'finance', 'community', 'store/orders'] as $path) {
        $this->get("http://{$this->host}/{$path}", $this->headers)->assertForbidden();
    }
    $this->get("http://{$this->host}/account", $this->headers)->assertOk();
});

test('extra custom roles cannot silently bypass event organiser scope but organisation owners remain unrestricted', function (): void {
    ($this->assign)();
    $role = App\Models\Role::query()->create(['name' => 'Additional event duties', 'guard_name' => 'web', 'tenant_id' => $this->tenant->id]);
    $this->organiser->assignRole($role);
    expect(EventStaffScope::restrictedEventIds($this->organiser, $this->tenant->id))->toBe([$this->event->id]);
    $this->organiser->assignRole('Org Superadmin');
    expect(EventStaffScope::restrictedEventIds($this->organiser, $this->tenant->id))->toBeNull();
});

test('event organiser cannot reach financial actions even on an assigned event', function (): void {
    ($this->assign)();
    $base = "http://{$this->host}/events/{$this->event->id}";
    $this->actingAs($this->organiser)->postJson($base.'/ticket-types', ['name' => 'Forged paid ticket', 'price' => 10000], $this->headers)->assertForbidden();
    $this->patchJson($base.'/member-benefits', [], $this->headers)->assertForbidden();
    $this->patchJson($base.'/contributions/settings', [], $this->headers)->assertForbidden();
    $this->getJson($base.'/data/finance', $this->headers)->assertForbidden();
    $this->getJson($base.'/data/promo-codes', $this->headers)->assertForbidden();
    $this->getJson($base.'/member-payments', $this->headers)->assertForbidden();
    $this->get($base.'/reports/audit-log', $this->headers)->assertForbidden();
});

test('organisers can cancel a free admission but cannot trigger a paid refund', function (): void {
    ($this->assign)();
    $free = EventRegistration::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'status' => EventRegistration::STATUS_CONFIRMED, 'amount' => 0, 'payment_reference' => null, 'member_benefit_required' => false]);
    $paid = EventRegistration::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'status' => EventRegistration::STATUS_CONFIRMED, 'amount' => 20000, 'payment_reference' => 'paid-source', 'member_benefit_required' => false]);
    $base = "http://{$this->host}/events/{$this->event->id}/registrations";
    $this->actingAs($this->organiser)->postJson($base."/{$free->id}/cancel", [], $this->headers)->assertOk();
    $this->postJson($base."/{$paid->id}/cancel", [], $this->headers)->assertForbidden();
    expect($paid->fresh()->status)->toBe(EventRegistration::STATUS_CONFIRMED);
});

test('organisers cannot change choice pricing or remove a price-bearing registration form', function (): void {
    ($this->assign)();
    $base = "http://{$this->host}/events/{$this->event->id}/form-fields";
    $payload = ['label' => 'Extra', 'field_key' => 'extra', 'field_type' => 'select', 'is_required' => false, 'options' => [['label' => 'Yes', 'value' => 'yes', 'price' => 100]]];
    $this->actingAs($this->organiser)->postJson($base, $payload, $this->headers)->assertForbidden();
    $field = $this->event->formFields()->create([...$payload, 'tenant_id' => $this->tenant->id, 'sort_order' => 1]);
    $this->deleteJson($base."/{$field->id}", [], $this->headers)->assertForbidden();
    $this->postJson($base.'/reorder', ['order' => [$field->id]], $this->headers)->assertForbidden();
});

test('organisers can add ordinary registration questions on their event', function (): void {
    ($this->assign)();
    $this->actingAs($this->organiser)->postJson("http://{$this->host}/events/{$this->event->id}/form-fields", ['label' => 'Institution', 'field_key' => 'institution', 'field_type' => 'text', 'is_required' => false], $this->headers)->assertCreated();
});

test('assigned event paths cannot smuggle another events bound resources', function (): void {
    ($this->assign)();
    $group = App\Models\EventParticipantGroup::query()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->other->id, 'name' => 'Other group', 'type' => 'manual']);
    $form = App\Models\EventDynamicForm::query()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->other->id, 'title' => 'Other form', 'slug' => 'other-form', 'type' => 'survey', 'schema' => []]);
    $abstract = App\Models\EventAbstract::query()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->other->id, 'title' => 'Other abstract', 'body' => 'Private', 'code' => 'ABS-SCOPE']);
    $base = "http://{$this->host}/events/{$this->event->id}";
    $this->actingAs($this->organiser)->getJson($base."/participant-groups/{$group->id}", $this->headers)->assertNotFound();
    $this->getJson($base."/dynamic-forms/{$form->id}", $this->headers)->assertNotFound();
    $this->getJson($base."/abstracts/{$abstract->id}", $this->headers)->assertNotFound();
});

test('organisers cannot link a session to another events abstract or speaker', function (): void {
    ($this->assign)();
    $abstract = App\Models\EventAbstract::query()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->other->id, 'title' => 'Other abstract', 'body' => 'Private', 'code' => 'ABS-LINK']);
    $speaker = App\Models\Speaker::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->other->speakers()->attach($speaker->id, ['id' => (string) Illuminate\Support\Str::uuid(), 'tenant_id' => $this->tenant->id]);
    $base = ['title' => 'Session', 'type' => 'lecture', 'starts_at' => now()->addDay()->toDateTimeString(), 'ends_at' => now()->addDay()->addHour()->toDateTimeString()];
    $url = "http://{$this->host}/events/{$this->event->id}/sessions";
    $this->actingAs($this->organiser)->postJson($url, [...$base, 'abstract_id' => $abstract->id], $this->headers)->assertUnprocessable()->assertJsonValidationErrors('abstract_id');
    $this->postJson($url, [...$base, 'speaker_ids' => [$speaker->id]], $this->headers)->assertUnprocessable()->assertJsonValidationErrors('speaker_ids.0');
});

test('material uploads cannot use another events session', function (): void {
    ($this->assign)();
    Illuminate\Support\Facades\Storage::fake('public');
    $session = App\Models\EventSession::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->other->id]);
    $this->actingAs($this->organiser)->postJson("http://{$this->host}/events/{$this->event->id}/materials", ['title' => 'Slides', 'session_id' => $session->id, 'download_limit' => 2, 'file' => Illuminate\Http\UploadedFile::fake()->create('slides.pdf', 1, 'application/pdf')], $this->headers)->assertUnprocessable()->assertJsonValidationErrors('session_id');
});

test('private event channels recheck roles assignments and active tenant membership', function (): void {
    ($this->assign)();
    $channels = Illuminate\Support\Facades\Broadcast::getChannels();
    foreach (['event.{eventId}.sessions', 'event.{eventId}.service-requests'] as $name) {
        $callback = $channels[$name];
        expect($callback($this->organiser, $this->event->id))->toBeTrue();
        EventStaffAssignment::query()->where('user_id', $this->organiser->id)->delete();
        expect($callback($this->organiser, $this->event->id))->toBeFalse();
        ($this->assign)();
        $this->organiser->removeRole('Event Organiser');
        expect($callback($this->organiser, $this->event->id))->toBeFalse();
        $this->organiser->assignRole('Event Organiser');
        $this->organiser->update(['status' => User::STATUS_INACTIVE]);
        expect($callback($this->organiser, $this->event->id))->toBeFalse();
        $this->organiser->update(['status' => User::STATUS_ACTIVE]);
        $this->tenant->users()->detach($this->organiser->id);
        expect($callback($this->organiser, $this->event->id))->toBeFalse();
        $this->tenant->users()->attach($this->organiser->id);
    }
});
