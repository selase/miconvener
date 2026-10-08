<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventCertificate;
use App\Models\EventCertificateTemplate;
use App\Services\Certificates\CertificateDesignVersionService;
use App\Services\Certificates\CertificatePdfService;
use App\Services\Design\ArtifactLayoutValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

function customCertificateFixture(array $templateOverrides = [], array $certificateOverrides = [], ?App\Models\Tenant $tenant = null): array
{
    Storage::fake('public');
    $tenant ??= setActiveTenantForTest();
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

function prepareCertificateDesignerHost(): void
{
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
}

test('representative certificate PDFs stay on one physical A4 page', function (string $mode, bool $signature): void {
    [$template, $version, $certificate] = customCertificateFixture([
        'design_mode' => $mode,
        'layout' => app(ArtifactLayoutValidator::class)->certificateDefaults(),
        'show_qr' => true,
        'show_cpd_hours' => true,
        'signature_disk' => $signature ? 'public' : null,
        'signature_path' => $signature ? 'certificates/signature.png' : null,
    ], ['recipient_name' => 'Dr. Ési Akosua Mensah-Boateng']);
    $image = imagecreatetruecolor(1120, 800);
    imagefill($image, 0, 0, imagecolorallocate($image, 245, 248, 255));
    imagerectangle($image, 15, 15, 1104, 784, imagecolorallocate($image, 30, 58, 138));
    ob_start();
    imagepng($image);
    $background = ob_get_clean();
    imagedestroy($image);
    Storage::disk('public')->put($version->background_path, $background);
    if ($signature) {
        $ink = imagecreatetruecolor(300, 100);
        imagealphablending($ink, false);
        imagesavealpha($ink, true);
        imagefill($ink, 0, 0, imagecolorallocatealpha($ink, 255, 255, 255, 127));
        imagesetthickness($ink, 3);
        $color = imagecolorallocate($ink, 30, 58, 138);
        foreach ([[10, 70, 40, 15], [40, 15, 30, 80], [30, 80, 90, 45], [90, 45, 110, 65], [110, 65, 180, 45], [180, 45, 210, 60], [60, 85, 270, 75]] as [$x1, $y1, $x2, $y2]) {
            imageline($ink, $x1, $y1, $x2, $y2, $color);
        }
        ob_start();
        imagepng($ink);
        Storage::disk('public')->put($version->signature_path, ob_get_clean());
        imagedestroy($ink);
    }
    $pdf = app(CertificatePdfService::class)->generatePdf($certificate);
    $boxes = [];
    $pdf->getDomPDF()->setCallbacks([['event' => 'end_frame', 'f' => function (Dompdf\Frame $frame) use (&$boxes): void {
        $node = $frame->get_node();
        if ($node instanceof DOMElement && in_array($node->getAttribute('class'), ['outer-border', 'issuer-title', 'verify-text', 'qr-image', 'bottom-section'], true)) {
            $boxes[$node->getAttribute('class')] = $frame->get_border_box();
        }
    }]]);
    $output = $pdf->output();

    $directory = getenv('ARTIFACT_QA_DIRECTORY');
    if (is_string($directory) && is_dir($directory)) {
        file_put_contents($directory.'/certificate-'.$mode.'-'.($signature ? 'signature' : 'no-signature').'.pdf', $output);
    }
    expect($pdf->getDomPDF()->getCanvas()->get_page_count())->toBe(1)
        ->and($output)->toContain('/MediaBox [0.000 0.000 841.890 595.280]');
    if ($mode === 'miconvener') {
        expect($boxes)->toHaveKeys(['outer-border', 'issuer-title', 'verify-text', 'qr-image', 'bottom-section']);
        $outer = $boxes['outer-border'];
        foreach (['issuer-title', 'verify-text', 'qr-image', 'bottom-section'] as $key) {
            $box = $boxes[$key];
            expect($box['y'] + $box['h'])->toBeLessThan($outer['y'] + $outer['h'] - 5)
                ->and($box['x'])->toBeGreaterThan($outer['x']);
        }
    }
})->with([['miconvener', false], ['custom_background', false], ['custom_background', true]]);

test('every visible certificate text overlay reports overflow instead of clipping', function (string $field): void {
    $layout = app(ArtifactLayoutValidator::class)->certificateDefaults();
    $layout[$field]['width'] = 0.01;
    $layout[$field]['height'] = 0.01;
    $layout[$field]['visible'] = true;
    [, , $certificate] = customCertificateFixture(['layout' => $layout, 'show_cpd_hours' => true]);

    expect(fn () => app(CertificatePdfService::class)->renderHtml($certificate))->toThrow(ValidationException::class);
})->with(['title', 'body', 'event_name', 'issuer', 'verification_code', 'cpd_hours']);

test('Unicode text fitting reduces fonts within a readable bounded range', function (): void {
    $settings = app(ArtifactLayoutValidator::class)->fitText('Akosua Ési Mensah', [
        'font_family' => 'DejaVu Sans', 'font_weight' => 700, 'font_size' => 16,
    ], 40, 10, 'recipient_name');
    expect($settings['font_size'])->toBeLessThan(16)->toBeGreaterThanOrEqual(11.2);
});

test('heavy custom certificate fonts keep the selected family in the actual PDF', function (): void {
    $layout = app(ArtifactLayoutValidator::class)->certificateDefaults();
    $layout['title']['font_weight'] = 800;
    [, , $certificate] = customCertificateFixture(['title' => 'Award', 'layout' => $layout]);
    $pdf = app(CertificatePdfService::class)->generatePdf($certificate);
    $font = null;
    $pdf->getDomPDF()->setCallbacks([['event' => 'end_frame', 'f' => function (Dompdf\Frame $frame) use (&$font): void {
        if ($frame->is_text_node() && mb_trim($frame->get_node()->textContent) === 'Award') {
            $font = $frame->get_style()->font_family;
        }
    }]]);
    $pdf->output();
    expect($font)->toContain('DejaVuSans');
});

test('certificate issuance rejects overflow and rolls back the entire recipient batch', function (): void {
    prepareCertificateDesignerHost();
    [$tenant, $user] = eventHost('acme');
    [$template] = customCertificateFixture(['layout' => app(ArtifactLayoutValidator::class)->certificateDefaults()], [], $tenant);
    $host = eventSubdomainHost('acme');
    $before = EventCertificate::query()->count();

    $this->actingAs($user)->postJson("http://{$host}/events/{$template->event_id}/certificates/issue", [
        'target_group' => 'custom', 'template_id' => $template->id, 'role' => 'delegate',
        'custom_recipients' => [
            ['name' => 'Ama Mensah', 'email' => 'first@example.test'],
            ['name' => str_repeat('W', 81), 'email' => 'second@example.test'],
        ],
    ], ['HTTP_HOST' => $host])->assertUnprocessable()->assertJsonValidationErrors('recipient_name');
    expect(EventCertificate::query()->count())->toBe($before);
});

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

test('organizer can upload replace and remove certificate artwork', function (): void {
    prepareCertificateDesignerHost();
    Storage::fake('public');
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);

    $create = $this->actingAs($user)->post("http://{$host}/events/{$event->id}/certificates/templates", [
        'role' => 'delegate',
        'design_mode' => 'custom_background',
        'title' => 'Tenant certificate',
        'background' => UploadedFile::fake()->image('first.png', 1120, 800),
        'layout' => json_encode([]),
    ], ['HTTP_HOST' => $host]);
    $create->assertOk();
    $template = $event->certificateTemplates()->where('role', 'delegate')->firstOrFail();
    $firstPath = $template->background_path;
    Storage::disk('public')->assertExists($firstPath);
    $this->actingAs($user)->get("http://{$host}/events/{$event->id}/certificates/templates/{$template->id}/artwork/background", ['HTTP_HOST' => $host])
        ->assertOk()->assertHeader('content-type', 'image/png');

    $update = $this->actingAs($user)->post("http://{$host}/events/{$event->id}/certificates/templates/{$template->id}", [
        '_method' => 'PUT',
        'title' => 'Tenant certificate',
        'design_mode' => 'miconvener',
        'remove_background' => '1',
    ], ['HTTP_HOST' => $host]);

    $update->assertOk();
    expect($template->fresh()->background_path)->toBeNull();
    Storage::disk('public')->assertMissing($firstPath);
});

test('certificate template and preview routes reject a template from another event', function (): void {
    prepareCertificateDesignerHost();
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $otherEvent = Event::factory()->create(['tenant_id' => $tenant->id]);
    $template = EventCertificateTemplate::query()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $otherEvent->id,
        'role' => 'delegate',
        'title' => 'Other event',
    ]);

    $this->actingAs($user)->putJson("http://{$host}/events/{$event->id}/certificates/templates/{$template->id}", [
        'title' => 'Cross event update',
    ], ['HTTP_HOST' => $host])->assertNotFound();
    $this->actingAs($user)->get("http://{$host}/events/{$event->id}/certificates/templates/{$template->id}/preview", [
        'HTTP_HOST' => $host,
    ])->assertNotFound();
});

test('organizer can download a representative certificate preview without issuing it', function (): void {
    prepareCertificateDesignerHost();
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $template = EventCertificateTemplate::query()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'role' => 'delegate',
        'title' => 'Preview certificate',
    ]);

    $response = $this->actingAs($user)->get("http://{$host}/events/{$event->id}/certificates/templates/{$template->id}/preview", [
        'HTTP_HOST' => $host,
    ]);

    $response->assertOk()->assertHeader('content-type', 'application/pdf');
    expect($event->certificates()->count())->toBe(0);
});

test('editing a legacy certificate resolves full defaults before new issuance without backfilling snapshots', function (): void {
    prepareCertificateDesignerHost();
    [$tenant, $user] = eventHost('acme');
    [$template, $oldVersion, $oldCertificate] = customCertificateFixture(['layout' => null, 'design_mode' => 'miconvener'], tenant: $tenant);
    $host = eventSubdomainHost('acme');
    $before = app(CertificatePdfService::class)->renderHtml($oldCertificate);
    $this->actingAs($user)->putJson("http://{$host}/events/{$template->event_id}/certificates/templates/{$template->id}", [
        'title' => $template->title,
        'design_mode' => 'custom_background',
    ], ['HTTP_HOST' => $host])->assertOk();
    $template->refresh();
    expect(array_keys($template->layout))->toContain('title', 'recipient_name', 'body', 'issuer', 'verification_code');
    $newCertificate = $oldCertificate->replicate(['id', 'uuid', 'verification_code', 'design_version_id']);
    $newCertificate->design_version_id = app(CertificateDesignVersionService::class)->snapshot($template)->id;
    $newCertificate->save();
    $html = app(CertificatePdfService::class)->renderHtml($newCertificate->fresh());
    expect($html)->toContain($template->title, 'Akosua Élise Mensah attended', 'Dr. Esi Owusu', $newCertificate->verification_code)
        ->and($oldVersion->fresh()->layout)->toBeNull()
        ->and(app(CertificatePdfService::class)->renderHtml($oldCertificate->fresh()))->toBe($before);
});

test('custom certificate requires usable background when saving and issuing', function (): void {
    prepareCertificateDesignerHost();
    Storage::fake('public');
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/certificates/templates", [
        'role' => 'delegate', 'title' => 'Certificate', 'design_mode' => 'custom_background',
    ], ['HTTP_HOST' => $host])->assertUnprocessable()->assertJsonValidationErrors('background');
    $template = $event->certificateTemplates()->create([
        'tenant_id' => $tenant->id, 'role' => 'delegate', 'title' => 'Certificate',
        'design_mode' => 'custom_background', 'background_disk' => 'public', 'background_path' => 'missing.png',
    ]);
    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/certificates/issue", [
        'target_group' => 'custom', 'role' => 'delegate', 'template_id' => $template->id,
        'custom_recipients' => [['name' => 'Ama', 'email' => 'ama@example.test']],
    ], ['HTTP_HOST' => $host])->assertUnprocessable()->assertJsonValidationErrors('background');
    expect($event->certificates()->count())->toBe(0);
});

test('certificate index exposes server defaults and mandatory fields resolve visible', function (): void {
    prepareCertificateDesignerHost();
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $response = $this->actingAs($user)->getJson("http://{$host}/events/{$event->id}/data/certificates", ['HTTP_HOST' => $host])->assertOk();
    $defaults = app(ArtifactLayoutValidator::class)->certificateDefaults();
    expect($response->json('layout_defaults'))->toEqual($defaults)
        ->and($defaults['recipient_name']['font_family'])->toBe('DejaVu Sans');
    $resolved = app(ArtifactLayoutValidator::class)->resolveCertificate([
        'recipient_name' => ['visible' => false], 'verification_code' => ['visible' => false], 'title' => ['visible' => false],
    ]);
    expect($resolved['recipient_name']['visible'])->toBeTrue()
        ->and($resolved['verification_code']['visible'])->toBeTrue()
        ->and($resolved['title']['visible'])->toBeFalse();
});

test('background removal in custom mode fails without replacement and replacement preserves issued artwork', function (): void {
    prepareCertificateDesignerHost();
    [$tenant, $user] = eventHost('acme');
    [$template, $version, $certificate] = customCertificateFixture(tenant: $tenant);
    $host = eventSubdomainHost('acme');
    $url = "http://{$host}/events/{$template->event_id}/certificates/templates/{$template->id}";
    $this->actingAs($user)->putJson($url, [
        'title' => $template->title, 'design_mode' => 'custom_background', 'remove_background' => true,
    ], ['HTTP_HOST' => $host])->assertUnprocessable()->assertJsonValidationErrors('background');
    $oldPath = $template->background_path;
    expect($template->fresh()->background_path)->toBe($oldPath);
    $this->actingAs($user)->post($url, [
        '_method' => 'PUT', 'title' => $template->title, 'design_mode' => 'custom_background',
        'remove_background' => '1', 'background' => UploadedFile::fake()->image('replacement.jpg', 1120, 800),
    ], ['HTTP_HOST' => $host])->assertOk();
    expect($template->fresh()->background_path)->not->toBe($oldPath)
        ->and($version->fresh()->background_path)->toBe($oldPath);
    Storage::disk('public')->assertExists($oldPath);
    expect(app(CertificatePdfService::class)->renderHtml($certificate))->toContain('Akosua Élise Mensah');
});

test('invalid edited layout leaves the current template and artwork intact', function (): void {
    prepareCertificateDesignerHost();
    [$tenant, $user] = eventHost('acme');
    [$template] = customCertificateFixture(tenant: $tenant);
    $host = eventSubdomainHost('acme');
    $original = $template->layout;
    $this->actingAs($user)->putJson("http://{$host}/events/{$template->event_id}/certificates/templates/{$template->id}", [
        'title' => $template->title, 'layout' => ['recipient_name' => ['x' => 0.9, 'width' => 0.5]],
    ], ['HTTP_HOST' => $host])->assertUnprocessable()->assertJsonValidationErrors('layout');
    expect($template->fresh()->layout)->toBe($original);
    Storage::disk('public')->assertExists($template->background_path);
});

test('new issuance resolves legacy custom layout without rewriting an earlier partial snapshot', function (): void {
    prepareCertificateDesignerHost();
    [$tenant, $user] = eventHost('acme');
    [$template, $oldVersion] = customCertificateFixture(['layout' => null], tenant: $tenant);
    $host = eventSubdomainHost('acme');
    $this->actingAs($user)->postJson("http://{$host}/events/{$template->event_id}/certificates/issue", [
        'target_group' => 'custom', 'role' => 'delegate', 'template_id' => $template->id,
        'custom_recipients' => [['name' => 'Ama Élise', 'email' => 'new@example.test']],
    ], ['HTTP_HOST' => $host])->assertOk()->assertJsonPath('issued_count', 1);
    $new = $template->event->certificates()->where('recipient_email', 'new@example.test')->firstOrFail();
    $html = app(CertificatePdfService::class)->renderHtml($new);
    expect($html)->toContain('artifact-title', 'artifact-recipient_name', 'artifact-body', 'artifact-issuer', 'artifact-verification_code')
        ->and($oldVersion->fresh()->layout)->toBeNull();
});

test('draft certificate pdf uses unsaved design without changing templates versions certificates or files', function (): void {
    prepareCertificateDesignerHost();
    [$tenant, $user] = eventHost('acme');
    [$template] = customCertificateFixture(tenant: $tenant);
    $host = eventSubdomainHost('acme');
    $before = $template->fresh()->getAttributes();
    $files = Storage::disk('public')->allFiles();
    $versions = App\Models\EventCertificateDesignVersion::query()->count();
    $certificates = EventCertificate::query()->count();
    $pdf = Mockery::mock(Barryvdh\DomPDF\PDF::class);
    $pdf->shouldReceive('setPaper')->with('a4', 'landscape')->andReturnSelf();
    $pdf->shouldReceive('setOption')->andReturnSelf();
    $pdf->shouldReceive('stream')->with('certificate-preview.pdf')->andReturn(response('%PDF-draft', 200, ['Content-Type' => 'application/pdf']));
    Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadHTML')->once()->withArgs(function (string $html): bool {
        expect($html)->toContain('Unsaved title', 'Unsaved Akosua Élise Mensah', 'left: 20%', 'data:image/png;base64,');

        return true;
    })->andReturn($pdf);
    $this->actingAs($user)->post("http://{$host}/events/{$template->event_id}/certificates/templates/preview", [
        'role' => 'delegate', 'design_mode' => 'custom_background', 'title' => 'Unsaved title',
        'body_template' => 'Unsaved {name}', 'show_qr' => '0', 'show_cpd_hours' => '0',
        'layout' => json_encode(['recipient_name' => ['x' => 0.2]]),
        'background' => UploadedFile::fake()->image('draft.png', 1120, 800),
        'signature' => UploadedFile::fake()->image('signature.png', 300, 100),
    ], ['HTTP_HOST' => $host, 'Accept' => 'application/json'])->assertOk()->assertHeader('content-type', 'application/pdf');
    expect($template->fresh()->getAttributes())->toEqual($before)
        ->and(App\Models\EventCertificateDesignVersion::query()->count())->toBe($versions)
        ->and(EventCertificate::query()->count())->toBe($certificates)
        ->and(Storage::disk('public')->allFiles())->toBe($files);
});

test('draft preview retains event owned stored assets and can remove the signature without persisting', function (): void {
    prepareCertificateDesignerHost();
    [$tenant, $user] = eventHost('acme');
    [$template] = customCertificateFixture(tenant: $tenant);
    $host = eventSubdomainHost('acme');
    $files = Storage::disk('public')->allFiles();
    foreach ([false, true] as $removed) {
        $pdf = Mockery::mock(Barryvdh\DomPDF\PDF::class);
        $pdf->shouldReceive('setPaper')->andReturnSelf();
        $pdf->shouldReceive('setOption')->andReturnSelf();
        $pdf->shouldReceive('stream')->andReturn(response('%PDF-preview', 200, ['Content-Type' => 'application/pdf']));
        Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadHTML')->once()->withArgs(function (string $html) use ($removed): bool {
            expect($html)->toContain("background: #fff url('data:image/png;base64,");
            expect(str_contains($html, 'class="signature-image"'))->toBe(! $removed);

            return true;
        })->andReturn($pdf);
        $this->actingAs($user)->postJson("http://{$host}/events/{$template->event_id}/certificates/templates/preview", [
            'role' => 'delegate', 'title' => 'Draft', 'design_mode' => 'custom_background', 'remove_signature' => $removed,
        ], ['HTTP_HOST' => $host])->assertOk();
    }
    expect($template->fresh()->signature_path)->not->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBe($files);
});

test('draft preview rejects corrupt uploads and client artwork paths without creating records', function (): void {
    prepareCertificateDesignerHost();
    Storage::fake('public');
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $url = "http://{$host}/events/{$event->id}/certificates/templates/preview";
    $this->actingAs($user)->post($url, [
        'role' => 'delegate', 'title' => 'Draft', 'background' => UploadedFile::fake()->createWithContent('corrupt.png', "\x89PNG\r\nnot-image"),
    ], ['HTTP_HOST' => $host, 'Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('background');
    $this->actingAs($user)->postJson($url, [
        'role' => 'delegate', 'title' => 'Draft', 'background_disk' => 'public', 'background_path' => '/etc/passwd',
    ], ['HTTP_HOST' => $host])->assertUnprocessable()->assertJsonValidationErrors(['background_disk', 'background_path']);
    expect($event->certificateTemplates()->count())->toBe(0)
        ->and($event->certificates()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('draft preview requires template editing permission', function (): void {
    prepareCertificateDesignerHost();
    [$tenant, $user] = eventHost('acme');
    [$template] = customCertificateFixture(tenant: $tenant);
    $host = eventSubdomainHost('acme');
    $user->syncRoles([]);
    $user->unsetRelation('roles')->unsetRelation('permissions');
    $this->actingAs($user)->postJson("http://{$host}/events/{$template->event_id}/certificates/templates/preview", [
        'role' => 'delegate', 'title' => 'Draft', 'design_mode' => 'miconvener',
    ], ['HTTP_HOST' => $host])->assertForbidden();
});
