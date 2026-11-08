<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventTicketType;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\User;
use App\Services\Events\AttendeeImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Mail::fake();
    Queue::fake();
    $this->tenant = Tenant::factory()->create(['slug' => 'capacity-claims', 'isolation_mode' => 'shared']);
    $this->actor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($this->actor->id);
    setPermissionsTeamId($this->tenant->id);
    $this->actor->assignRole('Org Admin');
    $this->actingAs($this->actor);
    app(App\Services\Tenancy\TenantContext::class)->setTenant($this->tenant);
    $this->host = 'capacity-claims.miconvener.test';
    $this->event = Event::factory()->published()->create(['tenant_id' => $this->tenant->id, 'capacity' => 1]);
    $this->import = function (?EventTicketType $ticket = null): App\Models\EventAttendeeImport {
        $service = app(AttendeeImport::class);
        $batch = $service->preview($this->event, $this->actor, UploadedFile::fake()->createWithContent('a.csv', "full_name,email,ticket_type\nImported,imported@example.test,".($ticket?->name ?? '')));
        $service->confirm($batch, (string) Illuminate\Support\Str::uuid(), $batch->rows()->pluck('id')->all());
        $service->process($batch->id);

        return $batch->fresh();
    };
});

test('first import respects every unresolved native admission claim in its capacity pool', function (string $status, bool $typed): void {
    $ticket = $typed ? EventTicketType::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'name' => 'General', 'capacity' => 1]) : null;
    $native = EventRegistration::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'status' => $status, 'ticket_type_id' => $ticket?->id, 'amount' => 5000, 'ticket_code' => null, 'qr_token' => null]);
    $batch = ($this->import)($ticket);
    expect($batch->rows()->first()->status)->toBe('failed')->and($this->event->registrations()->count())->toBe(1)->and($native->fresh()->status)->toBe($status);
})->with(['pending_payment', 'pending_approval', 'pending_member_verification', 'pending_benefit_review'])->with([false, true]);

test('mixed public signup reserves a pending paid place and does not mutate a duplicate signup', function (bool $typed): void {
    TenantFeature::create(['tenant_id' => $this->tenant->id, 'feature_key' => 'paid_tickets', 'enabled' => true]);
    $this->event->update(['capacity' => $typed ? 1 : 2, 'ticket_price' => $typed ? 0 : 5000]);
    $ticket = $typed ? EventTicketType::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'name' => 'General', 'capacity' => 2, 'price' => 5000]) : null;
    ($this->import)($ticket);
    $post = fn (string $email) => $this->post("http://{$this->host}/e/{$this->event->slug}/register", ['full_name' => 'Native', 'email' => $email, 'ticket_type_id' => $ticket?->id], ['HTTP_HOST' => $this->host]);
    $post('first@example.test')->assertRedirect();
    $post('first@example.test')->assertRedirect();
    $post('second@example.test')->assertRedirect();
    expect($this->event->registrations()->where('email', 'first@example.test')->first()?->status)->toBe('pending_payment')->and($this->event->registrations()->where('email', 'second@example.test')->first()?->status)->toBe('waitlisted')->and($this->event->registrations()->count())->toBe(3);
    if ($typed) {
        $this->get("http://{$this->host}/e/{$this->event->slug}", ['HTTP_HOST' => $this->host])->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page->where('event.ticket_types.0.is_sold_out', true)->where('event.capacity_reservation_note', fn ($note): bool => str_contains($note, 'reserved')));
    }
})->with([false, true]);

test('mixed history keeps claims conservative after the imported attendee cancels and promotion cannot steal a claim', function (): void {
    ($this->import)();
    $this->event->registrations()->where('registration_source', 'external_import')->update(['status' => 'cancelled']);
    EventRegistration::factory()->pendingPayment()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id]);
    $waiting = EventRegistration::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'status' => 'waitlisted', 'waitlist_position' => 1, 'ticket_code' => null, 'qr_token' => null]);
    app(App\Services\Events\MemberBenefitLifecycle::class)->promoteWaiting($this->event, null);
    expect($waiting->fresh()->status)->toBe('waitlisted')->and($waiting->fresh()->ticket_code)->toBeNull();
});

test('mixed history refuses direct gateway starts for a waitlisted registration', function (): void {
    ($this->import)();
    $waiting = EventRegistration::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'status' => 'waitlisted', 'amount' => 5000, 'ticket_code' => null, 'qr_token' => null]);
    Http::preventStrayRequests();
    foreach (['paystack', 'stripe'] as $provider) {
        $this->postJson("http://{$this->host}/e/{$this->event->slug}/checkout/{$waiting->id}/{$provider}", [], ['HTTP_HOST' => $this->host])->assertUnprocessable();
    }
    expect($waiting->fresh()->status)->toBe('waitlisted');
});

test('legacy excluded paid rows with untracked checkout links still reserve a place', function (string $status, ?int $version): void {
    EventRegistration::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'status' => $status, 'amount' => 5000, 'payment_reference' => null, 'checkout_admission_guard_version' => $version]);
    $batch = ($this->import)();
    expect($batch->rows()->first()->status)->toBe('failed')->and($this->event->registrations()->where('registration_source', 'external_import')->count())->toBe(0);
})->with(['waitlisted', 'cancelled', 'rejected'])->with([null, 2]);

test('same-second new guarded waitlist does not reserve another place while a legacy paid waitlist does', function (): void {
    $this->travelTo(now()->startOfSecond());
    TenantFeature::create(['tenant_id' => $this->tenant->id, 'feature_key' => 'paid_tickets', 'enabled' => true]);
    $this->event->update(['capacity' => 2, 'ticket_price' => 5000]);
    $legacy = EventRegistration::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'status' => 'waitlisted', 'amount' => 5000]);
    ($this->import)();
    $this->post("http://{$this->host}/e/{$this->event->slug}/register", ['full_name' => 'New waitlist', 'email' => 'new-wait@example.test'], ['HTTP_HOST' => $this->host])->assertRedirect();
    $new = $this->event->registrations()->where('email', 'new-wait@example.test')->firstOrFail();
    expect($new->status)->toBe('waitlisted')->and($new->checkout_admission_guard_version)->toBe(1)->and($new->created_at->toDateTimeString())->toBe($legacy->created_at->toDateTimeString());
    expect(app(App\Services\Events\AdmissionClaims::class)->count($this->event))->toBe(2);
});

test('all events refuse invalid direct checkout starts even before importing', function (string $status): void {
    $row = EventRegistration::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'status' => $status, 'amount' => 5000]);
    Http::preventStrayRequests();
    $this->postJson("http://{$this->host}/e/{$this->event->slug}/checkout/{$row->id}/paystack", [], ['HTTP_HOST' => $this->host])->assertUnprocessable();
})->with(['waitlisted', 'cancelled', 'rejected', 'pending_approval']);

test('mixed approval and offline verification cannot promote a nonclaiming row past pending claims', function (): void {
    ($this->import)();
    $this->event->update(['capacity' => 2, 'allow_offline_payments' => true]);
    EventRegistration::factory()->pendingPayment()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'amount' => 5000]);
    $approval = EventRegistration::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'status' => 'pending_approval', 'ticket_code' => null, 'qr_token' => null]);
    $this->postJson("http://{$this->host}/events/{$this->event->id}/registrations/{$approval->id}/approve", [], ['HTTP_HOST' => $this->host])->assertUnprocessable();
    expect($approval->fresh()->status)->toBe('pending_approval');
    $waiting = EventRegistration::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'status' => 'waitlisted', 'amount' => 5000, 'offline_payment_status' => 'pending_verification', 'ticket_code' => null, 'qr_token' => null]);
    $this->postJson("http://{$this->host}/events/{$this->event->id}/registrations/{$waiting->id}/approve-offline", [], ['HTTP_HOST' => $this->host])->assertUnprocessable();
    expect($waiting->fresh()->status)->toBe('waitlisted')->and(App\Models\EventLedgerEntry::count())->toBe(0);
});

test('native events without imports retain their existing valid signup behavior', function (): void {
    EventRegistration::factory()->pendingPayment()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id]);
    $this->post("http://{$this->host}/e/{$this->event->slug}/register", ['full_name' => 'Native', 'email' => 'native-unmixed@example.test'], ['HTTP_HOST' => $this->host])->assertRedirect();
    expect($this->event->registrations()->where('email', 'native-unmixed@example.test')->firstOrFail()->status)->toBe('confirmed');
});
