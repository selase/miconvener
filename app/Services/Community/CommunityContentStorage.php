<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\CommunityLibraryVersion;
use App\Models\Tenant;
use App\Services\Tenancy\StorageMeter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class CommunityContentStorage
{
    public function __construct(private StorageMeter $meter) {}

    /** Caller serialises quota changes with the tenant lock.
     * @return array{disk:string,path:string,mime:string,size:int,checksum:string}
     */
    public function put(Tenant $tenant, UploadedFile $file): array
    {
        $limit = $tenant->featureLimitValue('storage_bytes');
        if ($limit !== null && $this->meter->calculateUsage($tenant) + $file->getSize() > $limit) {
            throw ValidationException::withMessages(['file' => 'This upload would exceed your organisation storage allowance.']);
        }
        $disk = app()->environment('production') ? 's3' : 'community-library-local';
        $mime = $file->getMimeType();
        $extensions = ['application/pdf' => 'pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (! isset($extensions[$mime]) || $file->getSize() > 20 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'Choose a PDF, DOCX, JPEG, PNG or WebP file up to 20MB.']);
        }
        $path = 'community-library/'.$tenant->id.'/'.Str::uuid().'.'.$extensions[$mime];
        $stored = Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'private']);
        if ($stored === false) {
            throw ValidationException::withMessages(['file' => 'The private file could not be stored. Try again.']);
        }
        $checksum = hash_file('sha256', $file->getRealPath());
        if ($checksum === false) {
            Storage::disk($disk)->delete($path);
            throw ValidationException::withMessages(['file' => 'The uploaded file could not be verified.']);
        }

        return ['disk' => $disk, 'path' => $path, 'mime' => $mime, 'size' => $file->getSize(), 'checksum' => $checksum];
    }

    public function stream(CommunityLibraryVersion $version): StreamedResponse
    {
        abort_unless(in_array($version->disk, ['community-library-local', 's3'], true)
            && str_starts_with($version->path, 'community-library/'.$version->tenant_id.'/'), 404);
        $disk = Storage::disk($version->disk);
        abort_unless($disk->exists($version->path), 404);
        $title = $version->item->title;
        $filename = (Str::slug($title) ?: 'document').'.'.pathinfo($version->path, PATHINFO_EXTENSION);

        return $disk->response($version->path, $filename, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Type' => $version->mime], $version->mime === 'application/pdf' ? 'inline' : 'attachment');
    }
}
