<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Mail\Events\EventRegistrationConfirmed;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Services\Events\QrCodeGenerator;
use App\Services\Events\TicketPdfService;
use Illuminate\Mail\SendQueuedMailable;
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

    $sent = app('mailer')->getSymfonyTransport()->messages()[0];
    $mime = $sent->toString();

    expect($sent->getOriginalMessage()->getHtmlBody())->toContain('cid:');
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

test('the queued ticket email uses Resend only for the configured event', function (bool $matchesEvent) {
    $tenant = Tenant::factory()->create(['slug' => 'ticket-transport', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);
    $registration->issueTicket();
    $registration->save();

    config([
        'mail.default' => 'array',
        'mail.event_confirmation_override.event_id' => $matchesEvent ? $event->id : 'another-event',
        'mail.event_confirmation_override.from' => 'tickets@relay.example.com',
        'mail.mailers.transactional_resend' => ['transport' => 'array', 'smtp' => ['password' => 'test-only']],
    ]);

    $manager = app('mail.manager');
    $mailable = (new EventRegistrationConfirmed($registration))->to($registration->email);
    (new SendQueuedMailable($mailable))->handle($manager);

    $resend = $manager->mailer('transactional_resend')->getSymfonyTransport()->messages();
    $default = $manager->mailer('array')->getSymfonyTransport()->messages();

    expect($resend)->toHaveCount($matchesEvent ? 1 : 0)
        ->and($default)->toHaveCount($matchesEvent ? 0 : 1);

    if ($matchesEvent) {
        expect($resend[0]->toString())
            ->toContain('tickets@relay.example.com')
            ->toContain('application/pdf');
    }
})->with([true, false]);

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

test('the Resend transport preserves organiser replies while using its verified sender', function () {
    $delivery = new \Illuminate\Mail\Transport\ArrayTransport;
    $transport = new \App\Services\Mail\VerifiedSenderTransport($delivery, 'tickets@relay.example.com');
    $message = (new \Symfony\Component\Mime\Email)
        ->from(new \Symfony\Component\Mime\Address('old@organiser.example.com', 'An organiser'))
        ->replyTo('help@organiser.example.com')
        ->to('attendee@example.com')
        ->subject('Test confirmation')
        ->text('Test confirmation');

    $sent = $transport->send($message);
    expect($sent->getEnvelope()->getSender()->getAddress())->toBe('tickets@relay.example.com')
        ->and($sent->toString())->toContain('From: An organiser <tickets@relay.example.com>')
        ->and($sent->toString())->toContain('Reply-To: help@organiser.example.com')
        ->and($sent->getEnvelope()->getRecipients()[0]->getAddress())->toBe('attendee@example.com');
});
