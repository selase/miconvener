<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\StoreListing;
use App\Models\StoreListingMedia;
use App\Models\Tenant;
use App\Scopes\MerchandiseVisibilityScope;
use App\Services\Tenancy\StorageMeter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final readonly class MerchandiseMediaStorage
{
    public function __construct(private StorageMeter $meter) {}

    /** The caller must authorise catalog management before writing. */
    public function put(Tenant $tenant, StoreListing $listing, UploadedFile $file): StoreListingMedia
    {
        $path = null;
        $disk = app()->environment('production') ? 's3' : 'commerce-media-local';
        try {
            return $tenant->getConnection()->transaction(function () use ($tenant, $listing, $file, $disk, &$path): StoreListingMedia {
                $locked = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
                abort_unless(app(CommerceCatalogRollout::class)->enabled($locked), 403);
                $current = StoreListing::withoutGlobalScope(MerchandiseVisibilityScope::class)->whereKey($listing->id)
                    ->where('listing_kind', StoreListing::KIND_MERCHANDISE)
                    ->whereHas('shop', fn ($shop) => $shop->where('tenant_id', $locked->id))->firstOrFail();
                $mime = $file->getMimeType();
                $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if (! $file->isValid() || ! isset($extensions[$mime]) || $file->getSize() > 5 * 1024 * 1024) {
                    throw ValidationException::withMessages(['photo' => 'Choose a JPEG, PNG or WebP image up to 5MB.']);
                }
                if (StoreListingMedia::withoutGlobalScope('merchandise')->where('listing_id', $current->id)->count() >= 8) {
                    throw ValidationException::withMessages(['photo' => 'A listing can have up to eight photos.']);
                }
                $limit = $locked->featureLimitValue('storage_bytes');
                if ($limit !== null && $this->meter->calculateUsage($locked) + $file->getSize() > $limit) {
                    throw ValidationException::withMessages(['photo' => 'This upload would exceed your organisation storage allowance.']);
                }
                $path = 'commerce-media/'.$locked->id.'/'.$current->id.'/'.Str::uuid().'.'.$extensions[$mime];
                $stored = Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'private']);
                if ($stored === false) {
                    throw ValidationException::withMessages(['photo' => 'The private image could not be stored. Try again.']);
                }
                $checksum = hash_file('sha256', $file->getRealPath());
                if ($checksum === false) {
                    throw ValidationException::withMessages(['photo' => 'The image could not be verified.']);
                }

                return StoreListingMedia::query()->create(['listing_id' => $current->id, 'media_type' => 'photo', 'file_path' => $path,
                    'disk' => $disk, 'mime' => $mime, 'byte_size' => $file->getSize(), 'checksum' => $checksum,
                    'status' => StoreListingMedia::STATUS_APPROVED, 'sort_order' => StoreListingMedia::withoutGlobalScope('merchandise')->where('listing_id', $current->id)->count()]);
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk($disk)->delete($path);
            }
            throw $exception;
        }
    }

    public function stream(StoreListingMedia $media): StreamedResponse
    {
        $listing = StoreListing::withoutGlobalScope(MerchandiseVisibilityScope::class)->with('shop')->findOrFail($media->listing_id);
        abort_unless($listing->listing_kind === StoreListing::KIND_MERCHANDISE
            && in_array($media->disk, ['commerce-media-local', 's3'], true)
            && in_array($media->mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            && ! str_contains($media->file_path, '..')
            && str_starts_with($media->file_path, 'commerce-media/'.$listing->shop->tenant_id.'/'.$listing->id.'/'), 404);
        $disk = Storage::disk($media->disk);
        abort_unless($disk->exists($media->file_path), 404);

        return $disk->response($media->file_path, 'photo.'.pathinfo($media->file_path, PATHINFO_EXTENSION),
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Type' => $media->mime], 'inline');
    }
}
