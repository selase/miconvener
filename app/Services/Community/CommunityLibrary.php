<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\CommunityLibraryItem;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class CommunityLibrary
{
    public function __construct(private CommunityContentStorage $storage, private CommunityLibraryRollout $rollout) {}

    /** @param array<string,mixed> $data */
    public function save(Tenant $tenant, array $data, ?UploadedFile $file, ?CommunityLibraryItem $candidate, int $actor): CommunityLibraryItem
    {
        $stored = null;
        try {
            return DB::connection('landlord')->transaction(function () use ($tenant, $data, $file, $candidate, $actor, &$stored): CommunityLibraryItem {
                $lockedTenant = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
                abort_unless($this->rollout->canPublish($lockedTenant), 403, 'Publishing requires the Library feature and an eligible plan. Existing documents remain readable.');
                $item = $candidate === null ? new CommunityLibraryItem(['tenant_id' => $tenant->id, 'created_by' => $actor])
                    : CommunityLibraryItem::withoutGlobalScopes()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($candidate->id);
                if ($file === null && $item->current_version_id === null && blank($data['plain_text'] ?? null)) {
                    throw ValidationException::withMessages(['plain_text' => 'Add notes or attach a document before saving.']);
                }
                $attributes = array_intersect_key($data, array_flip(['title', 'description', 'plain_text', 'audience', 'state', 'release_at', 'kind', 'event_id', 'session_id']));
                $item->fill($attributes);
                $item->save();
                if ($file !== null) {
                    $stored = $this->storage->put($lockedTenant, $file);
                    $version = $item->versions()->create([...$stored, 'tenant_id' => $tenant->id, 'edition' => $data['edition'] ?? now()->toDateString(), 'created_by' => $actor]);
                    $item->update(['current_version_id' => $version->id]);
                }

                return $item->load('currentVersion');
            });
        } catch (Throwable $exception) {
            if (is_array($stored)) {
                Storage::disk($stored['disk'])->delete($stored['path']);
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    public function payload(CommunityLibraryItem $item, bool $staff = false): array
    {
        $version = $item->currentVersion;
        $tenant = $item->tenant;
        $file = $version === null ? null : ['id' => $version->id, 'edition' => $version->edition, 'mime' => $version->mime, 'size' => $version->size,
            'url' => $staff ? route('tenant.community.library.version', ['subdomain' => $tenant->slug, 'item' => $item->id, 'version' => $version->id]) : route('attendee.my.library.file', ['tenantSlug' => $tenant->slug, 'item' => $item->id])];

        return ['id' => $item->id, 'kind' => $item->kind, 'title' => $item->title, 'description' => $item->description, 'plain_text' => $item->plain_text, 'state' => $item->state, 'release_at' => $item->release_at?->toIso8601String(), 'updated_at' => $item->updated_at->toIso8601String(), 'file' => $file,
            ...($staff ? ['audience' => $item->audience, 'event_id' => $item->event_id, 'session_id' => $item->session_id] : [])];
    }
}
