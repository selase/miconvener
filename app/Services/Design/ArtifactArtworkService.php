<?php

declare(strict_types=1);

namespace App\Services\Design;

use App\ValueObjects\Design\StoredArtwork;
use finfo;
use GdImage;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class ArtifactArtworkService
{
    private const int MAX_FILE_BYTES = 10 * 1024 * 1024;

    private const int MAX_DIMENSION = 8000;

    private const int MAX_PIXELS = 40_000_000;

    /** @var array<string, string> */
    private const array EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
    ];

    /** @var list<string> */
    private const array KINDS = [
        'badge-background',
        'certificate-background',
        'certificate-signature',
    ];

    public function __construct(private readonly FilesystemManager $filesystems) {}

    public function store(UploadedFile $file, string $tenantId, string $eventId, string $kind): StoredArtwork
    {
        $this->validateNamespace($tenantId, $eventId, $kind);

        $upload = $this->encodeUpload($file);
        $encoded = $upload['bytes'];
        $mime = $upload['mime'];
        $width = $upload['width'];
        $height = $upload['height'];

        $disk = config('app.env') === 'production' ? 's3' : 'public';
        $extension = self::EXTENSIONS[$mime];
        $path = sprintf(
            'tenants/%s/events/%s/artwork/%s/%s.%s',
            $tenantId,
            $eventId,
            $kind,
            Str::uuid()->toString(),
            $extension,
        );
        $filesystem = $this->filesystems->disk($disk);

        try {
            if (! $filesystem->put($path, $encoded)) {
                throw new RuntimeException('Artwork could not be stored.');
            }
        } catch (Throwable $exception) {
            $filesystem->delete($path);

            if ($exception instanceof RuntimeException && $exception->getMessage() === 'Artwork could not be stored.') {
                throw $exception;
            }

            throw new RuntimeException('Artwork could not be stored.', previous: $exception);
        }

        return new StoredArtwork($disk, $path, $mime, $width, $height);
    }

    public function uploadDataUri(UploadedFile $file): string
    {
        $upload = $this->encodeUpload($file);

        return sprintf('data:%s;base64,%s', $upload['mime'], base64_encode($upload['bytes']));
    }

    public function dataUri(string $disk, string $path): string
    {
        $bytes = $this->contents($disk, $path);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if (! is_string($mime) || ! isset(self::EXTENSIONS[$mime])) {
            throw new RuntimeException('Stored artwork is not a supported image.');
        }

        return sprintf('data:%s;base64,%s', $mime, base64_encode($bytes));
    }

    public function contents(string $disk, string $path): string
    {
        try {
            $bytes = $this->filesystems->disk($disk)->get($path);
        } catch (Throwable $exception) {
            throw new RuntimeException('Stored artwork is unavailable.', previous: $exception);
        }

        if (! is_string($bytes) || $bytes === '') {
            throw new RuntimeException('Stored artwork is unavailable.');
        }

        return $bytes;
    }

    public function delete(string $disk, ?string $path): void
    {
        if (blank($path)) {
            return;
        }

        $this->filesystems->disk($disk)->delete($path);
    }

    /** @return array{bytes: string, mime: string, width: int, height: int} */
    private function encodeUpload(UploadedFile $file): array
    {
        $fileSize = $file->getSize();

        if ($fileSize === false || $fileSize > self::MAX_FILE_BYTES) {
            $this->invalid('Artwork must not exceed 10 MB.');
        }

        $bytes = file_get_contents($file->getRealPath());

        if (! is_string($bytes) || $bytes === '' || mb_strlen($bytes) > self::MAX_FILE_BYTES) {
            $this->invalid('Artwork could not be read or exceeds 10 MB.');
        }

        $details = @getimagesizefromstring($bytes);

        if ($details === false || ! isset(self::EXTENSIONS[$details['mime']])) {
            $this->invalid('Artwork must be a valid PNG or JPEG image.');
        }

        $mime = $details['mime'];
        $width = $details[0];
        $height = $details[1];

        if ($width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION || ($width * $height) > self::MAX_PIXELS) {
            $this->invalid('Artwork dimensions are too large.');
        }

        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            $this->invalid('Artwork could not be decoded safely.');
        }

        try {
            $encoded = $this->encode($image, $mime);
        } finally {
            imagedestroy($image);
        }

        return ['bytes' => $encoded, 'mime' => $mime, 'width' => $width, 'height' => $height];
    }

    private function validateNamespace(string $tenantId, string $eventId, string $kind): void
    {
        $safeSegment = '/\A[A-Za-z0-9][A-Za-z0-9_-]{0,127}\z/';

        if (preg_match($safeSegment, $tenantId) !== 1 || preg_match($safeSegment, $eventId) !== 1 || ! in_array($kind, self::KINDS, true)) {
            $this->invalid('Artwork storage namespace is invalid.');
        }
    }

    private function encode(GdImage $image, string $mime): string
    {
        if ($mime === 'image/png') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        ob_start();

        $encoded = $mime === 'image/png'
            ? imagepng($image, null, 8)
            : imagejpeg($image, null, 90);
        $bytes = ob_get_clean();

        if (! $encoded || ! is_string($bytes) || $bytes === '') {
            throw new RuntimeException('Artwork could not be re-encoded.');
        }

        return $bytes;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['artwork' => $message]);
    }
}
