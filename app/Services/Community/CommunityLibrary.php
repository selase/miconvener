<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\CommunityLibraryItem;
use App\Models\CommunityLibraryVersion;
use App\Models\CommunityLibraryWrite;
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
                $writeKey = $data['write_key'] ?? null;
                $fingerprint = $writeKey === null ? null : $this->fingerprint($data, $file, $candidate);
                if ($writeKey !== null) {
                    $previous = CommunityLibraryWrite::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('actor_id', $actor)->where('write_key', $writeKey)->first();
                    if ($previous !== null) {
                        abort_unless(hash_equals($previous->fingerprint, $fingerprint), 409, 'This save key was already used for different content. Reopen the editor before saving changed content.');

                        return CommunityLibraryItem::withoutGlobalScopes()->where('tenant_id', $tenant->id)->with('currentVersion')->findOrFail($previous->item_id);
                    }
                }
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

                if ($writeKey !== null) {
                    CommunityLibraryWrite::query()->create(['tenant_id' => $tenant->id, 'actor_id' => $actor, 'write_key' => $writeKey, 'fingerprint' => $fingerprint, 'item_id' => $item->id]);
                }

                return $item->load('currentVersion');
            });
        } catch (Throwable $exception) {
            if (is_array($stored)) {
                try {
                    $referenced = CommunityLibraryVersion::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('disk', $stored['disk'])->where('path', $stored['path'])->exists();
                    if (! $referenced) {
                        Storage::disk($stored['disk'])->delete($stored['path']);
                    }
                } catch (Throwable) {
                    // An uncertain commit must never erase a possibly committed edition.
                }
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

    /** @param array<string,mixed> $data */
    private function fingerprint(array $data, ?UploadedFile $file, ?CommunityLibraryItem $candidate): string
    {
        $attributes = [];
        foreach (['title', 'description', 'plain_text', 'audience', 'state', 'release_at', 'kind', 'event_id', 'session_id', 'edition'] as $key) {
            $attributes[$key] = $data[$key] ?? null;
        }
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($canonical, $value);
        };

        return hash('sha256', json_encode(['item' => $candidate?->id, 'data' => $canonical($attributes), 'file' => $file === null ? null : ['checksum' => hash_file('sha256', $file->getRealPath()), 'mime' => $file->getMimeType(), 'size' => $file->getSize()]], JSON_THROW_ON_ERROR));
    }
}
