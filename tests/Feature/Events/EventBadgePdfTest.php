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
use Mockery;
use RuntimeException;

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
    $registration->setRelation('seatAssignment', new \App\Models\EventSeatAssignment(['seat_label' => 'Seat A14']));
    $template = app(BadgeTemplateService::class)->forEvent($event);
    $template->update(['tier_styles' => ['vip' => ['background_color' => '#7C3AED', 'text_color' => '#FFFFFF']]]);

    $html = app(BadgePdfService::class)->renderHtml($event, $template->fresh(), collect([$registration]));

    expect($html)->toContain('Ési Mensah', 'VIP Access', '#7C3AED', 'Seat A14', 'data:image/svg+xml;base64,')
        ->and($html)->not->toContain('>SEAT PLACEHOLDER<');
    $pdf = app(BadgePdfService::class)->generate($event, $template->fresh(), collect([$registration]));
    $fonts = [];
    $pdf->getDomPDF()->setCallbacks([['event' => 'end_frame', 'f' => function (\Dompdf\Frame $frame) use (&$fonts): void {
        if ($frame->is_text_node() && in_array(mb_trim($frame->get_node()->textContent), ['VIP Access', 'Seat A14'], true)) {
            $fonts[mb_trim($frame->get_node()->textContent)] = $frame->get_style()->font_family;
        }
    }]]);
    $output = $pdf->output();
    expect($pdf->getDomPDF()->getCanvas()->get_page_count())->toBe(1);
    expect($fonts)->toHaveKeys(['VIP Access', 'Seat A14']);
    foreach ($fonts as $font) {
        expect($font)->toContain('DejaVuSans');
    }
    $directory = getenv('ARTIFACT_QA_DIRECTORY');
    if (is_string($directory) && is_dir($directory)) {
        file_put_contents($directory.'/badges-vip.pdf', $output);
    }
    $layout = $template->layout;
    $layout['qr']['visible'] = false;
    $layout['seat_label']['visible'] = false;
    $template->update(['layout' => $layout]);
    $hidden = app(BadgePdfService::class)->renderHtml($event, $template, collect([$registration]));
    expect($hidden)->not->toContain('Seat A14', 'alt="QR"');
});

test('badge PDFs use corner crop marks and square QR geometry', function (): void {
    [$tenant] = eventHost('acme');
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $template = app(BadgeTemplateService::class)->forEvent($event);
    $layout = $template->layout;
    $layout['qr']['width'] = 0.3;
    $layout['qr']['height'] = 0.2;
    $template->update(['layout' => $layout]);

    $html = app(BadgePdfService::class)->renderHtml($event, $template, collect([badgePdfRegistration($event)]));
    expect($html)->toContain('class="crop-mark', 'width:14mm;height:14mm')
        ->not->toContain('dashed', 'object-fit');
    $template->update(['sheet_settings' => ['paper' => 'a4', 'margin_mm' => 8, 'gap_mm' => 3, 'crop_marks' => false]]);
    expect(app(BadgePdfService::class)->renderHtml($event, $template, collect([badgePdfRegistration($event)])))
        ->not->toContain('class="crop-mark');
});

test('badge event text overflow fails before producing a silently clipped badge', function (): void {
    [$tenant] = eventHost('acme');
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'name' => str_repeat('W', 200)]);
    $template = app(BadgeTemplateService::class)->forEvent($event);
    expect(fn () => app(BadgePdfService::class)->renderHtml($event, $template, collect([badgePdfRegistration($event)])))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('legacy badge sheets with insufficient crop clearance return an actionable error', function (): void {
    [$tenant] = eventHost('acme');
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $template = app(BadgeTemplateService::class)->forEvent($event);
    $template->update(['sheet_settings' => ['paper' => 'a4', 'margin_mm' => 8, 'gap_mm' => 0, 'crop_marks' => true]]);
    expect(fn () => app(BadgePdfService::class)->renderHtml($event, $template, collect([badgePdfRegistration($event)])))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
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

test('badge export retries preserve history while intentional reprints create new history', function (): void {
    [$tenant, $user] = eventHost('acme');
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $first = badgePdfRegistration($event);
    $second = badgePdfRegistration($event);
    $host = eventSubdomainHost('acme');
    $url = "http://{$host}/events/{$event->id}/badges/sheet";
    $reference = (string) \Illuminate\Support\Str::uuid();
    $payload = ['registration_ids' => [$first->id], 'export_reference' => $reference];
    $this->actingAs($user)->postJson($url, $payload, ['HTTP_HOST' => $host])->assertOk();
    $this->postJson($url, $payload, ['HTTP_HOST' => $host])->assertOk();
    expect(EventBadgePrint::query()->count())->toBe(1);
    $this->postJson($url, ['registration_ids' => [$second->id], 'export_reference' => $reference], ['HTTP_HOST' => $host])
        ->assertUnprocessable()->assertJsonValidationErrors('export_reference');
    $this->postJson($url, ['registration_ids' => [$first->id], 'export_reference' => (string) \Illuminate\Support\Str::uuid()], ['HTTP_HOST' => $host])->assertOk();
    expect(EventBadgePrint::query()->count())->toBe(2);
});

test('actual badge pdf page counts match one partial full and multiple sheets', function (string $paper, int $count, int $pages, int $width = 100, int $height = 70): void {
    [$tenant] = eventHost('acme');
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $template = app(BadgeTemplateService::class)->forEvent($event);
    $template->update(['width_mm' => $width, 'height_mm' => $height, 'sheet_settings' => ['paper' => $paper, 'margin_mm' => 8, 'gap_mm' => 3, 'crop_marks' => true]]);
    $registrations = collect(range(1, $count))->map(fn (int $index) => badgePdfRegistration($event, ['full_name' => "Attendee {$index}"]));
    $pdf = app(BadgePdfService::class)->generate($event, $template, $registrations);
    $output = $pdf->output();
    expect($pdf->getDomPDF()->getCanvas()->get_page_count())->toBe($pages);
    $directory = getenv('ARTIFACT_QA_DIRECTORY');
    if (is_string($directory) && is_dir($directory)) {
        file_put_contents($directory.'/badges-'.$paper.'-'.$count.'-'.$width.'x'.$height.'.pdf', $output);
    }
})->with([
    ['a4', 1, 1], ['a4', 2, 1], ['a4', 3, 1], ['a4', 7, 3],
    ['letter', 1, 1], ['letter', 2, 1], ['letter', 3, 1], ['letter', 7, 3],
    ['a4', 8, 1, 90, 55], ['a4', 9, 2, 90, 55], ['letter', 8, 1, 90, 55], ['letter', 9, 2, 90, 55],
    ['a4', 1, 1, 194, 281], ['letter', 1, 1, 199, 263],
]);

test('badges embed trusted tenant storage logos with no remote access', function (string $disk, string $environment): void {
    config(['app.env' => $environment]);
    \Illuminate\Support\Facades\Storage::fake($disk);
    [$tenant, $user] = eventHost('acme');
    $image = \Illuminate\Http\UploadedFile::fake()->image('logo.png', 100, 100);
    \Illuminate\Support\Facades\Storage::disk($disk)->put('tenant/logo/org.png', file_get_contents($image->getRealPath()));
    $tenant->update(['logo' => 'tenant/logo/org.png']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $template = app(BadgeTemplateService::class)->forEvent($event);
    $layout = $template->layout;
    $layout['tenant_logo']['visible'] = true;
    $template->update(['layout' => $layout]);
    $html = app(BadgePdfService::class)->renderHtml($event, $template, collect([badgePdfRegistration($event)]));
    expect($html)->toContain('alt="Tenant logo"', 'data:image/png;base64,', "font-family:'DejaVu Sans'");
    $host = eventSubdomainHost('acme');
    $this->actingAs($user)->getJson("http://{$host}/events/{$event->id}/data/badges", ['HTTP_HOST' => $host])
        ->assertOk()->assertJsonPath('layout_defaults.tenant_logo.visible', false)
        ->assertJsonPath('tenant_logo', fn ($value) => str_starts_with($value, 'data:image/png;base64,'));
    $tenant->update(['logo' => 'https://untrusted.example/logo.png']);
    expect(app(BadgeTemplateService::class)->tenantLogo($event->fresh()))->toBeNull();
})->with([['public', 'testing'], ['s3', 'production']]);

test('badge renderer failures never record print history', function (): void {
    [$tenant, $user] = eventHost('acme');
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = badgePdfRegistration($event);
    $pdf = Mockery::mock(\Barryvdh\DomPDF\PDF::class);
    $pdf->shouldReceive('output')->once()->andThrow(new RuntimeException('PDF output failed'));
    \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadHTML')->once()->andReturn($pdf);
    $pdf->shouldReceive('setPaper', 'setOption')->andReturnSelf();
    $host = eventSubdomainHost('acme');
    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/badges/sheet", ['registration_ids' => [$registration->id]], ['HTTP_HOST' => $host])->assertServerError();
    expect(EventBadgePrint::query()->count())->toBe(0);
});
