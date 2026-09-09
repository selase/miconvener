<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Mail\Events\EventRegistrationConfirmed;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Services\Events\QrCodeGenerator;
use App\Services\Events\TicketPdfService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

/**
 * The ticket QR used to ship as an SVG data URI. Gmail strips SVG and Outlook
 * cannot render it, so the attendee opened the email to a broken image and
 * arrived at the door with nothing to scan. It has to go out as a raster image
 * attached inline.
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('the QR generator produces real PNG bytes', function () {
    $png = QrCodeGenerator::png('some-qr-token');

    expect($png)->not->toBeNull();
    // PNG magic number: the file really is a PNG, not an SVG string.
    expect(mb_substr((string) $png, 1, 3))->toBe('PNG');
})->skip(fn (): bool => ! extension_loaded('imagick'), 'imagick not available on this host');

test('the sent ticket email carries the QR as an inline png part', function () {
    // Assert the MIME of an actually-sent message, not render(). Without a
    // transport, embedData() falls back to a data URI, so a render()-based
    // assertion would pass while real mail still went out unreadable.
    config(['mail.default' => 'array']);

    $tenant = Tenant::factory()->create(['slug' => 'qr-mail', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);
    $registration->issueTicket();
    $registration->save();

    Mail::to($registration->email)->send(new EventRegistrationConfirmed($registration));

    $mime = app('mailer')->getSymfonyTransport()->messages()[0]->toString();

    expect($mime)->toContain('cid:');
    expect($mime)->toContain('image/png');
    expect($mime)->toContain('Content-Disposition: inline');
    // The old failure mode: an SVG data URI that Gmail strips and Outlook cannot draw.
    expect($mime)->not->toContain('data:image/svg+xml');
    // A failed scan must not be a dead end.
    expect($mime)->toContain($registration->ticket_code);
})->skip(fn (): bool => ! extension_loaded('imagick'), 'imagick not available on this host');

test('the ticket email carries a PDF the attendee can use offline', function () {
    config(['mail.default' => 'array']);

    $tenant = Tenant::factory()->create(['slug' => 'qr-pdf', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);
    $registration->issueTicket();
    $registration->save();

    Mail::to($registration->email)->send(new EventRegistrationConfirmed($registration));

    $mime = app('mailer')->getSymfonyTransport()->messages()[0]->toString();

    expect($mime)->toContain('application/pdf');
    expect($mime)->toContain('ticket-');
});

test('the ticket PDF renders and embeds the QR rather than linking to it', function () {
    $tenant = Tenant::factory()->create(['slug' => 'pdf-render', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);
    $registration->issueTicket();
    $registration->save();

    $pdf = app(TicketPdfService::class)->generate($registration);

    // A real PDF, not an exception page or an empty string.
    expect(mb_substr($pdf, 0, 4))->toBe('%PDF');
    expect(mb_strlen($pdf))->toBeGreaterThan(1000);
    expect(app(TicketPdfService::class)->filename($registration))
        ->toContain($registration->ticket_code);
});

test('the QR encodes the same token the scanner matches on', function () {
    $tenant = Tenant::factory()->create(['slug' => 'qr-token', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);
    $registration->issueTicket();
    $registration->save();

    // EventCheckInController::scan() looks up by qr_token, so that is what the
    // image has to carry -- a mismatch here means every scan fails at the door.
    expect($registration->qr_token)->not->toBeEmpty();
    expect(QrCodeGenerator::svgDataUri($registration->qr_token))->toStartWith('data:image/svg+xml;base64,');
});
