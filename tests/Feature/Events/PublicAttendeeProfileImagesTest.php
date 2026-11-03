<?php

declare(strict_types=1);

use App\Models\EventAttendeeProfileImage;
use App\Services\Events\Profiles\ProfileImages;
use App\Services\Events\Profiles\ProfilePublication;
use App\Services\Tenancy\StorageMeter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/PublicAttendeeProfileScenario.php';

function profilePhoto(string $format = 'png'): UploadedFile
{
    $image = imagecreatetruecolor(640, 480);
    imagefill($image, 0, 0, imagecolorallocate($image, 40, 80, 120));
    ob_start();
    match ($format) {
        'jpeg' => imagejpeg($image),
        'webp' => imagewebp($image),
        default => imagepng($image),
    };
    $bytes = ob_get_clean();

    return UploadedFile::fake()->createWithContent('photo.'.$format, $bytes);
}

it('decodes supported photos and retains only a private square derivative', function (string $format): void {
    Storage::fake('attendee-profiles-local');
    [, , $registration, $session] = attendeeProfileScenario();
    $image = app(ProfileImages::class)->upload($registration, $session, profilePhoto($format));
    $bytes = Storage::disk($image->disk)->get($image->path);
    $details = getimagesizefromstring($bytes);
    expect($details[0])->toBe(512)->and($details[1])->toBe(512)->and($details['mime'])->toBe('image/webp')
        ->and($image->stored_at)->not->toBeNull()->and($image->profile_id)->toBeNull()
        ->and($image->byte_size)->toBe(mb_strlen($bytes));
    $profile = app(ProfilePublication::class)->publish($registration, $session, attendeeProfileInput(['image_id' => $image->id]));
    expect($profile->image_id)->toBe($image->id);
})->with(['png', 'jpeg', 'webp']);

it('refuses unsupported remote malformed oversized and animated inputs without reserving storage', function (string $bytes): void {
    [, , $registration, $session] = attendeeProfileScenario();
    expect(fn () => app(ProfileImages::class)->upload($registration, $session, UploadedFile::fake()->createWithContent('photo.png', $bytes)))
        ->toThrow(ValidationException::class);
    expect(EventAttendeeProfileImage::withoutGlobalScopes()->count())->toBe(0);
})->with(['<svg/>', 'https://example.test/photo.png', 'not an image', str_repeat('x', 2097153), "RIFF\x00\x00\x00\x00WEBPANIM"]);

it('binds pending uploads to their verified session', function (): void {
    Storage::fake('attendee-profiles-local');
    [, , $registration, $session] = attendeeProfileScenario();
    $image = app(ProfileImages::class)->upload($registration, $session, profilePhoto());
    $session->migrate();
    expect(fn () => app(ProfilePublication::class)->publish($registration, $session, attendeeProfileInput(['image_id' => $image->id])))
        ->toThrow(ValidationException::class);
});

it('includes pending and retired files until deletion is confirmed', function (): void {
    Storage::fake('attendee-profiles-local');
    [$tenant, , $registration, $session] = attendeeProfileScenario();
    $before = app(StorageMeter::class)->calculateUsage($tenant);
    $image = app(ProfileImages::class)->upload($registration, $session, profilePhoto());
    expect(app(StorageMeter::class)->calculateUsage($tenant))->toBe($before + $image->byte_size);
    $image->forceFill(['retired_at' => now()])->save();
    expect(app(StorageMeter::class)->calculateUsage($tenant))->toBe($before + $image->byte_size);
    $image->forceFill(['deleted_at' => now()])->save();
    expect(app(StorageMeter::class)->calculateUsage($tenant))->toBe($before);
});

it('enforces allowance without preventing text publication', function (): void {
    Storage::fake('attendee-profiles-local');
    [$tenant, , $registration, $session] = attendeeProfileScenario();
    $tenant->forceFill(['storage_limit_snapshot' => 0])->save();
    expect(fn () => app(ProfileImages::class)->upload($registration, $session, profilePhoto()))->toThrow(ValidationException::class);
    expect(app(ProfilePublication::class)->publish($registration, $session, attendeeProfileInput())->state)->toBe('published');
});

it('rejects excessive pixels before decoding and animated PNG containers', function (): void {
    [, , $registration, $session] = attendeeProfileScenario();
    $file = profilePhoto();
    $original = file_get_contents($file->getRealPath());
    $huge = substr_replace($original, pack('NN', 6000, 6000), 16, 8);
    $animated = mb_substr($original, 0, 33).pack('N', 8).'acTL'.pack('NN', 2, 0).pack('N', 0).mb_substr($original, 33);
    foreach ([$huge, $animated] as $bytes) {
        expect(fn () => app(ProfileImages::class)->upload($registration, $session, UploadedFile::fake()->createWithContent('photo.png', $bytes)))->toThrow(ValidationException::class);
    }
    expect(EventAttendeeProfileImage::withoutGlobalScopes()->count())->toBe(0);
});

it('limits abandoned uploads and refuses expired upload attachment', function (): void {
    Storage::fake('attendee-profiles-local');
    [, , $registration, $session] = attendeeProfileScenario();
    $service = app(ProfileImages::class);
    $first = $service->upload($registration, $session, profilePhoto());
    $service->upload($registration, $session, profilePhoto());
    $service->upload($registration, $session, profilePhoto());
    expect(fn () => $service->upload($registration, $session, profilePhoto()))->toThrow(ValidationException::class);
    $this->travelTo($first->expires_at);
    expect(fn () => app(ProfilePublication::class)->publish($registration, $session, attendeeProfileInput(['image_id' => $first->id])))->toThrow(ValidationException::class);
});

it('keeps durable cleanup intent and current publication after a failed storage write', function (): void {
    [, , $registration, $session] = attendeeProfileScenario();
    $profile = app(ProfilePublication::class)->publish($registration, $session, attendeeProfileInput());
    $disk = Mockery::mock(Illuminate\Filesystem\FilesystemAdapter::class);
    $disk->shouldReceive('put')->andReturn(false);
    Storage::set('attendee-profiles-local', $disk);
    expect(fn () => app(ProfileImages::class)->upload($registration, $session, profilePhoto()))->toThrow(ValidationException::class);
    $intent = EventAttendeeProfileImage::withoutGlobalScopes()->sole();
    expect($intent->stored_at)->toBeNull()->and($intent->retired_at)->not->toBeNull()
        ->and($intent->path)->not->toBeEmpty()->and($profile->fresh()->state)->toBe('published');
});

it('offers a private native upload endpoint with no raw file or storage key in the response', function (): void {
    Storage::fake('attendee-profiles-local');
    [, , $registration, $session] = attendeeProfileScenario();
    $this->withSession($session->all())->post('/my/events/'.$registration->id.'/public-profile/photo', ['photo' => profilePhoto()], ['Accept' => 'application/json'])
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonStructure(['image' => ['id']])
        ->assertJsonMissingPath('image.path')->assertJsonMissingPath('image.proof_hash');
});

it('fails closed when existing storage usage cannot be measured under a finite cap', function (): void {
    [$tenant, , $registration, $session] = attendeeProfileScenario();
    $tenant->forceFill(['storage_limit_snapshot' => 104857600])->save();
    $disk = Mockery::mock(Illuminate\Filesystem\FilesystemAdapter::class);
    $disk->shouldReceive('allFiles')->andThrow(new RuntimeException('Storage unavailable'));
    Storage::partialMock()->shouldReceive('disk')->with('tenant')->andReturn($disk);
    expect(fn () => app(ProfileImages::class)->upload($registration, $session, profilePhoto()))->toThrow(ValidationException::class);
    expect(EventAttendeeProfileImage::withoutGlobalScopes()->count())->toBe(0);
});
