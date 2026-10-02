<?php

declare(strict_types=1);

use App\Services\Design\ArtifactArtworkService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Storage::fake('public');
    config(['app.env' => 'testing']);
});

test('it re-encodes and stores png artwork in a tenant and event namespace', function (): void {
    $artwork = app(ArtifactArtworkService::class)->store(
        UploadedFile::fake()->image('certificate.png', 640, 480),
        'tenant-123',
        'event-456',
        'certificate-background',
    );

    expect($artwork->disk)->toBe('public')
        ->and($artwork->mime)->toBe('image/png')
        ->and($artwork->width)->toBe(640)
        ->and($artwork->height)->toBe(480)
        ->and($artwork->path)->toMatch('#^tenants/tenant-123/events/event-456/artwork/certificate-background/[a-f0-9-]+\.png$#');

    Storage::disk('public')->assertExists($artwork->path);
});

test('it re-encodes jpeg artwork and reports the detected content type', function (): void {
    $artwork = app(ArtifactArtworkService::class)->store(
        UploadedFile::fake()->image('badge.jpg', 320, 240),
        'tenant-123',
        'event-456',
        'badge-background',
    );

    expect($artwork->mime)->toBe('image/jpeg')
        ->and($artwork->path)->toEndWith('.jpg');
});

test('it strips trailing metadata while re-encoding artwork', function (): void {
    $source = UploadedFile::fake()->image('source.png', 100, 100);
    $bytes = (string) file_get_contents($source->getRealPath()).'EXIF-PRIVATE-METADATA';

    $artwork = app(ArtifactArtworkService::class)->store(
        UploadedFile::fake()->createWithContent('source.png', $bytes),
        'tenant-123',
        'event-456',
        'certificate-background',
    );

    expect(Storage::disk('public')->get($artwork->path))
        ->not->toContain('EXIF-PRIVATE-METADATA');
});

test('it rejects mime-spoofed and corrupt artwork before storage', function (UploadedFile $file): void {
    expect(fn () => app(ArtifactArtworkService::class)->store(
        $file,
        'tenant-123',
        'event-456',
        'certificate-background',
    ))->toThrow(ValidationException::class);

    Storage::disk('public')->assertDirectoryEmpty('tenants');
})->with([
    'gif declared as png' => fn (): UploadedFile => UploadedFile::fake()->createWithContent(
        'spoofed.png',
        base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true),
    ),
    'corrupt png bytes' => fn (): UploadedFile => UploadedFile::fake()->createWithContent(
        'broken.png',
        "\x89PNG\r\nnot-an-image",
    ),
]);

test('it rejects artwork exceeding file or decoded dimension limits', function (UploadedFile $file): void {
    expect(fn () => app(ArtifactArtworkService::class)->store(
        $file,
        'tenant-123',
        'event-456',
        'badge-background',
    ))->toThrow(ValidationException::class);
})->with([
    'file size' => fn (): UploadedFile => UploadedFile::fake()->create('large.png', 10_241, 'image/png'),
    'width' => fn (): UploadedFile => UploadedFile::fake()->image('wide.png', 8_001, 10),
]);

test('it rejects unsafe namespace segments and unsupported artwork kinds', function (string $tenantId, string $eventId, string $kind): void {
    expect(fn () => app(ArtifactArtworkService::class)->store(
        UploadedFile::fake()->image('safe.png', 100, 100),
        $tenantId,
        $eventId,
        $kind,
    ))->toThrow(ValidationException::class);
})->with([
    'tenant traversal' => ['../tenant', 'event-456', 'badge-background'],
    'event traversal' => ['tenant-123', '../event', 'badge-background'],
    'unknown kind' => ['tenant-123', 'event-456', '../secrets'],
]);

test('it reads stored artwork as a data uri and deletes nullable paths safely', function (): void {
    $service = app(ArtifactArtworkService::class);
    $artwork = $service->store(
        UploadedFile::fake()->image('signature.png', 120, 60),
        'tenant-123',
        'event-456',
        'certificate-signature',
    );

    expect($service->dataUri($artwork->disk, $artwork->path))
        ->toStartWith('data:image/png;base64,');

    $service->delete($artwork->disk, null);
    $service->delete($artwork->disk, $artwork->path);

    Storage::disk('public')->assertMissing($artwork->path);
});

test('it reads artwork through an s3 compatible disk without a public url', function (): void {
    Storage::fake('s3');
    $source = UploadedFile::fake()->image('background.jpg', 100, 100);
    $bytes = (string) file_get_contents($source->getRealPath());
    Storage::disk('s3')->put('private/background.jpg', $bytes);

    expect(app(ArtifactArtworkService::class)->dataUri('s3', 'private/background.jpg'))
        ->toStartWith('data:image/jpeg;base64,');
});

test('it throws and attempts cleanup when storage cannot persist re-encoded artwork', function (): void {
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('put')->once()->andReturnFalse();
    $disk->shouldReceive('delete')->once();

    $manager = Mockery::mock(FilesystemManager::class);
    $manager->shouldReceive('disk')->with('public')->andReturn($disk);

    $service = new ArtifactArtworkService($manager);

    expect(fn () => $service->store(
        UploadedFile::fake()->image('certificate.png', 640, 480),
        'tenant-123',
        'event-456',
        'certificate-background',
    ))->toThrow(RuntimeException::class, 'Artwork could not be stored.');
});
