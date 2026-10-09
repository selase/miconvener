<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Storage::fake('local');
});

test('artifact font catalog includes locally available families and real weights', function (): void {
    [$tenant, $user] = eventHost('acme');
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $host = eventSubdomainHost('acme');
    $response = $this->actingAs($user)->getJson("http://{$host}/events/{$event->id}/artifact-fonts", ['HTTP_HOST' => $host]);
    $response->assertOk();
    expect($response->json('fonts'))->toHaveCount(12);
    $lato = collect($response->json('fonts'))->firstWhere('family', 'Lato');
    expect($lato['weights'])->toBe([400, 700])->and($lato['faces'])->toHaveCount(2);
});

test('font uploads are scoped immutable and remain printable after archiving', function (): void {
    [$tenant, $user] = eventHost('acme');
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $host = eventSubdomainHost('acme');
    $url = "http://{$host}/events/{$event->id}/artifact-fonts";
    $file = new UploadedFile(base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf'), 'Brand.ttf', 'font/ttf', null, true);
    $response = $this->actingAs($user)->postJson($url, ['font' => $file, 'name' => 'Brand Sans', 'license_confirmed' => true], ['HTTP_HOST' => $host])->assertCreated();
    $family = $response->json('font.family');
    $response->assertJsonPath('font.weights', [400]);
    $faceUrl = $response->json('font.faces.0.url');
    $this->get($faceUrl, ['HTTP_HOST' => $host])->assertOk()->assertHeader('content-type', 'font/ttf');
    $font = \App\Models\ArtifactFont::query()->where('family', $family)->firstOrFail();
    $this->deleteJson($url.'/'.$font->id, [], ['HTTP_HOST' => $host])->assertNoContent();
    $catalog = $this->getJson($url, ['HTTP_HOST' => $host])->assertOk()->json('fonts');
    expect(collect($catalog)->firstWhere('family', $family)['archived'])->toBeTrue();
    expect(app(\App\Services\Design\ArtifactFontRegistry::class)->path($family, 400, (string) $tenant->id))->toBeFile();
    $other = \App\Models\Tenant::factory()->create();
    expect(fn () => app(\App\Services\Design\ArtifactFontRegistry::class)->path($family, 400, (string) $other->id))->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('invalid font uploads and untrusted links are rejected without downloading', function (): void {
    Http::preventStrayRequests();
    [$tenant, $user] = eventHost('acme');
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $host = eventSubdomainHost('acme');
    $url = "http://{$host}/events/{$event->id}/artifact-fonts";
    $this->actingAs($user)->postJson($url, ['font' => UploadedFile::fake()->createWithContent('Bad.ttf', 'not a font'), 'name' => 'Bad', 'license_confirmed' => true], ['HTTP_HOST' => $host])->assertUnprocessable()->assertJsonValidationErrors('font');
    $this->postJson($url, ['source' => 'http://127.0.0.1/private.ttf', 'license_confirmed' => true], ['HTTP_HOST' => $host])->assertUnprocessable()->assertJsonValidationErrors('source');
    Http::assertNothingSent();
});

test('Google family and link imports retain local regular and bold files', function (string $source): void {
    $regular = file_get_contents(public_path('assets/fonts/artifacts/lato-Lato-Regular.ttf'));
    $bold = file_get_contents(public_path('assets/fonts/artifacts/lato-Lato-Bold.ttf'));
    Http::preventStrayRequests();
    Http::fake([
        'https://fonts.googleapis.com/*' => Http::response('@font-face{font-weight:400;src:url(https://fonts.gstatic.com/test/regular.ttf)}@font-face{font-weight:700;src:url(https://fonts.gstatic.com/test/bold.ttf)}'),
        'https://fonts.gstatic.com/test/regular.ttf' => Http::response($regular),
        'https://fonts.gstatic.com/test/bold.ttf' => Http::response($bold),
    ]);
    [$tenant, $user] = eventHost('acme');
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $host = eventSubdomainHost('acme');
    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/artifact-fonts", ['source' => $source, 'license_confirmed' => true], ['HTTP_HOST' => $host])->assertCreated()->assertJsonPath('font.weights', [400, 700]);
    Http::assertSentCount(3);
    $registry = app(\App\Services\Design\ArtifactFontRegistry::class);
    $family = $response->json('font.family');
    expect(file_get_contents($registry->path($family, 400, (string) $tenant->id)))->toBe($regular)
        ->and($registry->metrics($family, (string) $tenant->id)->getFont($family, 'bold'))->not->toBeNull();
})->with(['Lato', 'https://fonts.google.com/specimen/Lato', 'https://fonts.googleapis.com/css2?family=Lato:wght@400;700']);

test('download failures and redirected font links return actionable errors', function (): void {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/private'])]);
    [$tenant, $user] = eventHost('acme');
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $host = eventSubdomainHost('acme');
    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/artifact-fonts", ['source' => 'Lato', 'license_confirmed' => true], ['HTTP_HOST' => $host])->assertUnprocessable()->assertJsonValidationErrors('source');
    Http::assertSentCount(1);
});

test('font endpoints enforce permissions and event ownership', function (): void {
    [$tenant, $user] = eventHost('acme');
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $other = Event::factory()->create();
    $host = eventSubdomainHost('acme');
    $this->actingAs($user)->getJson("http://{$host}/events/{$other->id}/artifact-fonts", ['HTTP_HOST' => $host])->assertNotFound();
    $user->syncRoles([]);
    $user->forgetCachedPermissions();
    $this->getJson("http://{$host}/events/{$event->id}/artifact-fonts", ['HTTP_HOST' => $host])->assertForbidden();
    $this->postJson("http://{$host}/events/{$event->id}/artifact-fonts", ['source' => 'Lato', 'license_confirmed' => true], ['HTTP_HOST' => $host])->assertForbidden();
});

test('new fonts use their actual font metrics and are embedded in badge PDFs', function (): void {
    [$tenant] = eventHost('acme');
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Font congress']);
    $template = app(\App\Services\Badges\BadgeTemplateService::class)->forEvent($event);
    $layout = $template->layout;
    $layout['attendee_name']['font_family'] = 'Lato';
    $template->update(['layout' => $layout]);
    $registration = \App\Models\EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'full_name' => 'Ési Mensah']);
    $pdf = app(\App\Services\Badges\BadgePdfService::class)->generate($event, $template, collect([$registration]));
    $fontPath = null;
    $pdf->getDomPDF()->setCallbacks([['event' => 'end_frame', 'f' => function (\Dompdf\Frame $frame) use (&$fontPath): void {
        if ($frame->is_text_node() && mb_trim($frame->get_node()->textContent) === 'Ési Mensah') {
            $fontPath = $frame->get_style()->font_family;
        }
    }]]);
    $output = $pdf->output();
    expect($fontPath)->toContain('lato_bold')->and($output)->toContain('/FontFile2')
        ->and($pdf->getDomPDF()->getCanvas()->get_page_count())->toBe(1);
});

test('single weight fonts reject unavailable bold settings', function (): void {
    $layout = app(\App\Services\Badges\BadgeTemplateService::class)->defaults(Event::factory()->make())['layout'];
    $layout['attendee_name']['font_family'] = 'Abel';
    $layout['attendee_name']['font_weight'] = 700;
    expect(fn () => app(\App\Services\Design\ArtifactLayoutValidator::class)->validate($layout, 'badge'))->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('font metrics are reused within a request for batch printing', function (): void {
    $first = app(\App\Services\Design\ArtifactFontRegistry::class)->metrics('Lato');
    $second = app(\App\Services\Design\ArtifactFontRegistry::class)->metrics('Lato');
    expect($first)->toBe($second);
});

test('an issued certificate embeds its archived imported font without an active tenant', function (): void {
    [$tenant] = eventHost('acme');
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $font = app(\App\Services\Design\ArtifactFontRegistry::class)->upload(new UploadedFile(public_path('assets/fonts/artifacts/lato-Lato-Regular.ttf'), 'Brand.ttf', 'font/ttf', null, true), (string) $tenant->id);
    $layout = app(\App\Services\Design\ArtifactLayoutValidator::class)->certificateDefaults();
    $layout['recipient_name']['font_family'] = $font->family;
    $layout['recipient_name']['font_weight'] = 400;
    Storage::fake('public');
    $artwork = app(\App\Services\Design\ArtifactArtworkService::class)->store(UploadedFile::fake()->image('certificate.png', 500, 350), (string) $tenant->id, (string) $event->id, 'certificate-background');
    $template = \App\Models\EventCertificateTemplate::query()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'role' => 'delegate', 'background_disk' => $artwork->disk, 'background_path' => $artwork->path, 'title' => 'Original certificate', 'design_mode' => 'custom_background', 'layout' => $layout, 'show_qr' => false, 'show_cpd_hours' => false]);
    $version = app(\App\Services\Certificates\CertificateDesignVersionService::class)->snapshot($template);
    $certificate = \App\Models\EventCertificate::query()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'template_id' => $template->id, 'design_version_id' => $version->id, 'recipient_name' => 'Ama Mensah', 'recipient_email' => 'ama@example.test', 'role' => 'delegate']);
    $font->update(['archived_at' => now()]);
    $template->update(['title' => 'Replacement certificate', 'layout' => app(\App\Services\Design\ArtifactLayoutValidator::class)->certificateDefaults()]);
    app(\App\Services\Tenancy\TenantContext::class)->clear();
    $pdf = app(\App\Services\Certificates\CertificatePdfService::class)->generatePdf($certificate);
    $renderedFont = null;
    $pdf->getDomPDF()->setCallbacks([['event' => 'end_frame', 'f' => function (\Dompdf\Frame $frame) use (&$renderedFont): void {
        if ($frame->is_text_node() && mb_trim($frame->get_node()->textContent) === 'Ama Mensah') {
            $renderedFont = $frame->get_style()->font_family;
        }
    }]]);
    expect($pdf->output())->toContain('/FontFile2')
        ->and($renderedFont)->toBe(app(\App\Services\Design\ArtifactFontRegistry::class)->metrics($font->family, (string) $tenant->id)->getFont($font->family, 'normal'))
        ->and($certificate->designVersion->layout['recipient_name']['font_family'])->toBe($font->family);
});

test('batch font preparation reads an imported face from storage only once per request', function (): void {
    [$tenant] = eventHost('acme');
    $bytes = file_get_contents(public_path('assets/fonts/artifacts/lato-Lato-Regular.ttf'));
    $registry = app(\App\Services\Design\ArtifactFontRegistry::class);
    $font = $registry->upload(new UploadedFile(public_path('assets/fonts/artifacts/lato-Lato-Regular.ttf'), 'Brand.ttf', 'font/ttf', null, true), (string) $tenant->id);
    $filesystem = Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
    $filesystem->shouldReceive('get')->once()->andReturn($bytes);
    Storage::shouldReceive('disk')->with('local')->andReturn($filesystem);
    for ($index = 0; $index < 100; $index++) {
        $registry->metrics($font->family, (string) $tenant->id);
        $registry->path($font->family, 400, (string) $tenant->id);
    }
});
