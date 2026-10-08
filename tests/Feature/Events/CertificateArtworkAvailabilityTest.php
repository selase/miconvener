<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventCertificate;
use App\Models\EventCertificateTemplate;
use App\Services\Certificates\CertificateDesignVersionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('historical artwork loss gives an actionable service unavailable response without counting a download', function (string $disk, string $bytes): void {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');
    Storage::fake($disk);
    if ($bytes !== '') {
        Storage::disk($disk)->put('artwork/lost.png', $bytes);
    }
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $template = EventCertificateTemplate::query()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'role' => 'delegate', 'title' => 'Certificate', 'design_mode' => 'custom_background', 'background_disk' => $disk, 'background_path' => 'artwork/lost.png']);
    $version = app(CertificateDesignVersionService::class)->snapshot($template);
    $certificate = EventCertificate::query()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'template_id' => $template->id, 'design_version_id' => $version->id, 'recipient_name' => 'Ama Mensah', 'recipient_email' => 'ama@example.test', 'role' => 'delegate']);
    $this->actingAs($user)->getJson("http://{$host}/events/{$event->id}/certificates/{$certificate->id}/download", ['HTTP_HOST' => $host])
        ->assertStatus(503)->assertJsonPath('message', 'Issued certificate artwork is unavailable. Contact the organizer to restore the original artwork.');
    expect($certificate->fresh()->download_count)->toBe(0);
})->with(['missing local' => ['public', ''], 'missing S3' => ['s3', ''], 'corrupt local' => ['public', 'invalid image'], 'corrupt S3' => ['s3', 'invalid image']]);

test('saved preview falls back visibly when stored background artwork is lost', function (): void {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');
    Storage::fake('public');
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $template = EventCertificateTemplate::query()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'role' => 'delegate', 'title' => 'Certificate', 'design_mode' => 'custom_background', 'background_disk' => 'public', 'background_path' => 'artwork/lost.png']);
    $this->actingAs($user)->get("http://{$host}/events/{$event->id}/certificates/templates/{$template->id}/preview", ['HTTP_HOST' => $host])
        ->assertOk()->assertHeader('X-Certificate-Preview-Warning', 'Background artwork is unavailable. This preview uses the MiConvener design.');
});

test('draft preview warns and falls back for previously saved unavailable artwork', function (): void {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');
    Storage::fake('public');
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $event->certificateTemplates()->create(['tenant_id' => $tenant->id, 'role' => 'delegate', 'title' => 'Certificate', 'design_mode' => 'custom_background', 'background_disk' => 'public', 'background_path' => 'artwork/lost.png']);
    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/certificates/templates/preview", ['role' => 'delegate', 'title' => 'Draft title', 'design_mode' => 'custom_background'], ['HTTP_HOST' => $host])
        ->assertOk()->assertHeader('X-Certificate-Preview-Warning', 'Background artwork is unavailable. This preview uses the MiConvener design.');
});

test('public verification keeps issued design metadata after template edits and deletion', function (): void {
    [$tenant] = eventHost('acme');
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $template = $event->certificateTemplates()->create(['tenant_id' => $tenant->id, 'role' => 'delegate', 'title' => 'Original award', 'issuer_name' => 'Original issuer']);
    $version = app(CertificateDesignVersionService::class)->snapshot($template);
    $certificate = EventCertificate::query()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'template_id' => $template->id, 'design_version_id' => $version->id, 'recipient_name' => 'Ama', 'recipient_email' => 'ama@example.test', 'role' => 'delegate']);
    $template->update(['title' => 'Replacement award', 'issuer_name' => 'Replacement issuer']);
    $template->delete();
    $this->get("http://miconvener.test/verify/cert/{$certificate->uuid}")->assertOk()->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page->where('certificate.title', 'Original award')->where('certificate.issuer_name', 'Original issuer'));
});
