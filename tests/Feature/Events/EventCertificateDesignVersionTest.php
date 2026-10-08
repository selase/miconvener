<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventCertificate;
use App\Models\EventCertificateTemplate;
use App\Services\Certificates\CertificateDesignVersionService;
use Illuminate\Support\Facades\Storage;

function designTemplate(array $overrides = []): EventCertificateTemplate
{
    $tenant = setActiveTenantForTest();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);

    return EventCertificateTemplate::query()->create($overrides + [
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'role' => EventCertificateTemplate::ROLE_DELEGATE,
        'title' => 'Certificate of Participation',
        'body_template' => 'Awarded to {name} for attending {event_name}.',
        'issuer_name' => 'MiConvener',
        'show_qr' => true,
        'show_cpd_hours' => false,
        'default_cpd_hours' => 0,
    ]);
}

test('it creates one immutable version and reuses it while rendering fields are unchanged', function (): void {
    $template = designTemplate();
    $service = app(CertificateDesignVersionService::class);

    $first = $service->snapshot($template);
    $second = $service->snapshot($template->fresh());

    expect($first->id)->toBe($second->id)
        ->and($first->version)->toBe(1)
        ->and($first->title)->toBe('Certificate of Participation')
        ->and($template->designVersions()->count())->toBe(1);
});

test('it creates a new version when rendering fields change without mutating the old version', function (): void {
    $template = designTemplate();
    $service = app(CertificateDesignVersionService::class);
    $first = $service->snapshot($template);

    $template->update([
        'title' => 'Certificate of Clinical Excellence',
        'show_cpd_hours' => true,
        'default_cpd_hours' => 8.5,
    ]);
    $second = $service->snapshot($template->fresh());

    expect($second->id)->not->toBe($first->id)
        ->and($second->version)->toBe(2)
        ->and($second->title)->toBe('Certificate of Clinical Excellence')
        ->and($second->show_cpd_hours)->toBeTrue()
        ->and($first->fresh()->title)->toBe('Certificate of Participation')
        ->and($first->fresh()->show_cpd_hours)->toBeFalse();
});

test('issued certificates retain their design version and its artwork after template edits', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('artwork/original.png', 'original-artwork');
    Storage::disk('public')->put('artwork/replacement.png', 'replacement-artwork');

    $template = designTemplate([
        'design_mode' => 'custom_background',
        'background_disk' => 'public',
        'background_path' => 'artwork/original.png',
    ]);
    $service = app(CertificateDesignVersionService::class);
    $version = $service->snapshot($template);

    $certificate = EventCertificate::query()->create([
        'tenant_id' => $template->tenant_id,
        'event_id' => $template->event_id,
        'template_id' => $template->id,
        'design_version_id' => $version->id,
        'recipient_name' => 'Ama Mensah',
        'recipient_email' => 'ama@example.test',
        'role' => 'delegate',
    ]);

    $template->update(['background_path' => 'artwork/replacement.png']);
    $replacement = $service->snapshot($template->fresh());

    expect($certificate->fresh()->designVersion->id)->toBe($version->id)
        ->and($certificate->fresh()->designVersion->background_path)->toBe('artwork/original.png')
        ->and($replacement->background_path)->toBe('artwork/replacement.png')
        ->and(Storage::disk('public')->exists('artwork/original.png'))->toBeTrue()
        ->and($service->deleteIfUnreferenced($version))->toBeFalse();
});

test('it deletes only unreferenced historical versions and their unreferenced artwork', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('artwork/original.png', 'original-artwork');
    Storage::disk('public')->put('artwork/replacement.png', 'replacement-artwork');

    $template = designTemplate([
        'design_mode' => 'custom_background',
        'background_disk' => 'public',
        'background_path' => 'artwork/original.png',
    ]);
    $service = app(CertificateDesignVersionService::class);
    $version = $service->snapshot($template);
    $template->update(['background_path' => 'artwork/replacement.png']);
    $service->snapshot($template->fresh());

    expect($service->deleteIfUnreferenced($version))->toBeTrue()
        ->and(Storage::disk('public')->exists('artwork/original.png'))->toBeFalse()
        ->and(Storage::disk('public')->exists('artwork/replacement.png'))->toBeTrue();
});

test('legacy certificates remain valid with no design version', function (): void {
    $template = designTemplate();

    $certificate = EventCertificate::query()->create([
        'tenant_id' => $template->tenant_id,
        'event_id' => $template->event_id,
        'template_id' => $template->id,
        'recipient_name' => 'Legacy Recipient',
        'recipient_email' => 'legacy@example.test',
        'role' => 'delegate',
    ]);

    expect($certificate->design_version_id)->toBeNull()
        ->and($certificate->designVersion)->toBeNull();
});

test('deleting a template preserves issued snapshots and cleans only unused artwork', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('artwork/issued.png', 'issued');
    Storage::disk('public')->put('artwork/current.png', 'current');
    $template = designTemplate(['background_disk' => 'public', 'background_path' => 'artwork/issued.png']);
    $version = app(CertificateDesignVersionService::class)->snapshot($template);
    $certificate = EventCertificate::query()->create([
        'tenant_id' => $template->tenant_id, 'event_id' => $template->event_id,
        'template_id' => $template->id, 'design_version_id' => $version->id,
        'recipient_name' => 'Ama Mensah', 'recipient_email' => 'ama@example.test', 'role' => 'delegate',
    ]);
    $template->update(['background_path' => 'artwork/current.png']);
    $template->delete();
    expect($certificate->fresh()->design_version_id)->toBe($version->id)
        ->and($version->fresh()->template_id)->toBeNull();
    Storage::disk('public')->assertExists('artwork/issued.png');
    Storage::disk('public')->assertMissing('artwork/current.png');
});

test('event recovery retains artwork and permanent bulk destruction cleans all artifact types', function (): void {
    Storage::fake('public');
    $template = designTemplate(['background_disk' => 'public', 'background_path' => 'artwork/current.png', 'signature_disk' => 'public', 'signature_path' => 'artwork/signature.png']);
    $event = $template->event;
    $version = app(CertificateDesignVersionService::class)->snapshot($template);
    EventCertificate::query()->create(['tenant_id' => $event->tenant_id, 'event_id' => $event->id, 'template_id' => $template->id, 'design_version_id' => $version->id, 'recipient_name' => 'Ama', 'recipient_email' => 'ama@example.test', 'role' => 'delegate']);
    App\Models\EventBadgeTemplate::query()->create(['event_id' => $event->id, 'tenant_id' => $event->tenant_id, 'background_disk' => 'public', 'background_path' => 'artwork/badge.png']);
    foreach (['current', 'signature', 'badge'] as $name) {
        Storage::disk('public')->put("artwork/{$name}.png", $name);
    }
    $event->delete();
    expect(Storage::disk('public')->allFiles())->toHaveCount(3);
    Event::forceDestroy([$event->id]);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('deleting a badge template cleans artwork while retaining files referenced by another template', function (): void {
    Storage::fake('public');
    $certificate = designTemplate(['background_disk' => 'public', 'background_path' => 'artwork/shared.png']);
    Storage::disk('public')->put('artwork/shared.png', 'shared');
    $badge = App\Models\EventBadgeTemplate::query()->create(['event_id' => $certificate->event_id, 'tenant_id' => $certificate->tenant_id, 'background_disk' => 'public', 'background_path' => 'artwork/shared.png']);
    $badge->delete();
    Storage::disk('public')->assertExists('artwork/shared.png');
    $certificate->delete();
    Storage::disk('public')->assertMissing('artwork/shared.png');
});

test('rolled back artifact deletion retains the restored records artwork', function (string $target): void {
    Storage::fake('public');
    Storage::disk('public')->put('artwork/rollback.png', 'retained');
    $template = designTemplate(['background_disk' => 'public', 'background_path' => 'artwork/rollback.png']);
    $connection = $template->getConnection();
    $connection->beginTransaction();
    try {
        if ($target === 'event') {
            $template->event->forceDelete();
        } else {
            $template->delete();
        }
    } finally {
        $connection->rollBack();
    }
    expect(EventCertificateTemplate::query()->find($template->id))->not->toBeNull();
    Storage::disk('public')->assertExists('artwork/rollback.png');
})->with(['template', 'event']);
