<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventBadgePrint;
use App\Models\EventRegistration;
use App\Models\EventTicketType;
use App\Services\Badges\BadgePdfService;
use App\Services\Badges\BadgeTemplateService;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function badgePdfRegistration(Event $event, array $overrides = []): EventRegistration
{
    return EventRegistration::factory()->create($overrides + [
        'tenant_id' => $event->tenant_id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);
}

test('badge renderer uses exact millimetre dimensions and deterministic sheet pagination', function (): void {
    [$tenant] = eventHost('acme');
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Medical Congress']);
    $template = app(BadgeTemplateService::class)->forEvent($event);
    $registrations = collect([
        badgePdfRegistration($event, ['full_name' => 'Ama A']),
        badgePdfRegistration($event, ['full_name' => 'Ama B']),
        badgePdfRegistration($event, ['full_name' => 'Ama C']),
        badgePdfRegistration($event, ['full_name' => 'Ama D']),
    ]);

    $html = app(BadgePdfService::class)->renderHtml($event, $template, $registrations);
    $pdf = app(BadgePdfService::class)->generate($event, $template, $registrations)->output();

    expect($html)->toContain('width: 100mm', 'height: 70mm')
        ->and(mb_substr_count($html, 'class="sheet"'))->toBe(2)
        ->and($pdf)->toStartWith('%PDF-')
        ->and($pdf)->toContain('/MediaBox [0.000 0.000 595.280 841.890]');
});

test('badge renderer honours optional fields tier overrides and qr images', function (): void {
    [$tenant] = eventHost('acme');
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $ticket = EventTicketType::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'badge_tier' => 'vip', 'name' => 'VIP Access']);
    $registration = badgePdfRegistration($event, ['ticket_type_id' => $ticket->id, 'full_name' => 'Ési Mensah']);
    $registration->load('ticketType');
    $template = app(BadgeTemplateService::class)->forEvent($event);
    $template->update(['tier_styles' => ['vip' => ['background_color' => '#7C3AED', 'text_color' => '#FFFFFF']]]);

    $html = app(BadgePdfService::class)->renderHtml($event, $template->fresh(), collect([$registration]));

    expect($html)->toContain('Ési Mensah', 'VIP Access', '#7C3AED', 'data:image/svg+xml;base64,')
        ->and($html)->not->toContain('>SEAT PLACEHOLDER<');
});

test('sheet endpoint validates event ownership and records print history only after successful output', function (): void {
    [$tenant, $user] = eventHost('acme');
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $valid = badgePdfRegistration($event, ['full_name' => 'Valid Attendee']);
    $otherEvent = Event::factory()->create(['tenant_id' => $tenant->id]);
    $foreign = badgePdfRegistration($otherEvent);
    $host = eventSubdomainHost('acme');

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/badges/sheet", [
        'registration_ids' => [$valid->id, $foreign->id],
    ], ['HTTP_HOST' => $host])->assertUnprocessable();
    expect(EventBadgePrint::query()->count())->toBe(0);

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/badges/sheet", [
        'registration_ids' => [$valid->id],
    ], ['HTTP_HOST' => $host]);
    $response->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(EventBadgePrint::query()->where('registration_id', $valid->id)->count())->toBe(1);
});

test('badge overflow and oversized batches fail without print history', function (): void {
    [$tenant, $user] = eventHost('acme');
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $long = badgePdfRegistration($event, ['full_name' => str_repeat('Long Name ', 10)]);
    $host = eventSubdomainHost('acme');

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/badges/sheet", [
        'registration_ids' => [$long->id],
    ], ['HTTP_HOST' => $host])->assertUnprocessable();
    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/badges/sheet", [
        'registration_ids' => array_fill(0, 101, $long->id),
    ], ['HTTP_HOST' => $host])->assertUnprocessable()->assertJsonValidationErrors('registration_ids');
    expect(EventBadgePrint::query()->count())->toBe(0);
});
