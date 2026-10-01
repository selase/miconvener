<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventCertificate;
use App\Models\EventCertificateTemplate;
use App\Services\Certificates\CertificateDesignVersionService;
use App\Services\Certificates\CertificatePdfService;
use App\Services\Design\ArtifactLayoutValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

function customCertificateFixture(array $templateOverrides = [], array $certificateOverrides = []): array
{
    Storage::fake('public');
    $tenant = setActiveTenantForTest();
    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Clinical Research Congress',
    ]);
    $background = UploadedFile::fake()->image('background.png', 1120, 800);
    $signature = UploadedFile::fake()->image('signature.png', 300, 100);
    Storage::disk('public')->put('certificates/background.png', (string) file_get_contents($background->getRealPath()));
    Storage::disk('public')->put('certificates/signature.png', (string) file_get_contents($signature->getRealPath()));

    $template = EventCertificateTemplate::query()->create($templateOverrides + [
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'role' => 'delegate',
        'design_mode' => 'custom_background',
        'title' => 'Certificate of Clinical Excellence',
        'body_template' => '{name} attended {event_name} on {date}.',
        'issuer_name' => 'Dr. Esi Owusu',
        'issuer_title' => 'Conference Chair',
        'background_disk' => 'public',
        'background_path' => 'certificates/background.png',
        'signature_disk' => 'public',
        'signature_path' => 'certificates/signature.png',
        'show_qr' => false,
        'show_cpd_hours' => false,
        'layout' => [
            'recipient_name' => [
                'x' => 0.15, 'y' => 0.34, 'width' => 0.70, 'height' => 0.10,
                'align' => 'center', 'font_family' => 'Helvetica', 'font_size' => 32,
                'font_weight' => 700, 'color' => '#1E3A8A', 'visible' => true,
            ],
            'verification_code' => [
                'x' => 0.68, 'y' => 0.88, 'width' => 0.25, 'height' => 0.05,
                'align' => 'right', 'font_family' => 'Courier', 'font_size' => 10,
                'font_weight' => 400, 'color' => '#111827', 'visible' => true,
            ],
        ],
    ]);
    $version = app(CertificateDesignVersionService::class)->snapshot($template);
    $certificate = EventCertificate::query()->create($certificateOverrides + [
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'template_id' => $template->id,
        'design_version_id' => $version->id,
        'recipient_name' => 'Akosua Élise Mensah',
        'recipient_email' => 'akosua@example.test',
        'role' => 'delegate',
        'cpd_hours' => 8.5,
    ]);

    return [$template, $version, $certificate];
}

test('certificate layouts accept only canonical allowlisted elements and styles', function (): void {
    $validator = app(ArtifactLayoutValidator::class);
    $layout = $validator->validate([
        'recipient_name' => [
            'x' => 0.15, 'y' => 0.34, 'width' => 0.70, 'height' => 0.10,
            'align' => 'center', 'font_family' => 'Helvetica', 'font_size' => 32,
            'font_weight' => 700, 'color' => '#1e3a8a', 'visible' => true,
        ],
    ], 'certificate');

    expect($layout['recipient_name']['color'])->toBe('#1E3A8A')
        ->and($layout['recipient_name']['x'])->toBe(0.15);
});

test('certificate layouts reject unknown elements invalid fonts and out of bounds boxes', function (array $layout): void {
    expect(fn () => app(ArtifactLayoutValidator::class)->validate($layout, 'certificate'))
        ->toThrow(ValidationException::class);
})->with([
    'unknown element' => [['secret_html' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1]]],
    'invalid font' => [['recipient_name' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 0.1, 'font_family' => 'Remote Font']]],
    'outside page' => [['recipient_name' => ['x' => 0.8, 'y' => 0, 'width' => 0.3, 'height' => 0.1]]],
]);

test('custom certificate html embeds private artwork and honours qr and cpd visibility', function (): void {
    [, , $certificate] = customCertificateFixture();

    $html = app(CertificatePdfService::class)->renderHtml($certificate);

    expect($html)->toContain('data:image/png;base64,')
        ->and($html)->toContain('Akosua Élise Mensah')
        ->and($html)->toContain($certificate->verification_code)
        ->and($html)->not->toContain('Verification QR')
        ->and($html)->not->toContain('Continuing Education');
});

test('custom certificate html renders optional signature qr and cpd hours when enabled', function (): void {
    [, , $certificate] = customCertificateFixture([
        'show_qr' => true,
        'show_cpd_hours' => true,
    ]);

    $html = app(CertificatePdfService::class)->renderHtml($certificate);

    expect($html)->toContain('class="signature-image"')
        ->and($html)->toContain('Verification QR')
        ->and($html)->toContain('8.5 Continuing Education');
});

test('issued certificate rendering remains unchanged after its template is edited', function (): void {
    [$template, $version, $certificate] = customCertificateFixture();
    $before = app(CertificatePdfService::class)->renderHtml($certificate);

    $template->update([
        'title' => 'A Completely Different Future Certificate',
        'show_qr' => true,
        'background_path' => null,
    ]);
    $after = app(CertificatePdfService::class)->renderHtml($certificate->fresh());

    expect($certificate->fresh()->design_version_id)->toBe($version->id)
        ->and($after)->toBe($before)
        ->and($after)->not->toContain('A Completely Different Future Certificate');
});

test('issued certificate rendering fails clearly when historical artwork is unavailable', function (): void {
    [, $version, $certificate] = customCertificateFixture();
    Storage::disk((string) $version->background_disk)->delete((string) $version->background_path);

    expect(fn () => app(CertificatePdfService::class)->renderHtml($certificate))
        ->toThrow(RuntimeException::class, 'Issued certificate artwork is unavailable.');
});

test('custom certificate rendering reports unsafe recipient overflow instead of clipping', function (): void {
    [, , $certificate] = customCertificateFixture(certificateOverrides: [
        'recipient_name' => str_repeat('Very Long Recipient Name ', 8),
    ]);

    expect(fn () => app(CertificatePdfService::class)->renderHtml($certificate))
        ->toThrow(ValidationException::class, 'Recipient name is too long for the certificate layout.');
});

test('custom certificate pdf uses exact a4 landscape output settings', function (): void {
    [, , $certificate] = customCertificateFixture();

    $pdf = app(CertificatePdfService::class)->outputPdf($certificate);

    expect($pdf)->toStartWith('%PDF-');
});
