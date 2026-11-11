<?php

declare(strict_types=1);

namespace App\Services\Design;

use App\Models\BadgeDesignRevision;
use App\Models\BadgeLibraryVersion;
use App\Models\EventBadgeTemplate;
use App\Models\EventCertificateDesignVersion;
use App\Models\EventCertificateTemplate;
use Illuminate\Database\Eloquent\Model;

final class ArtifactArtworkCleanup
{
    public function __construct(private readonly ArtifactArtworkService $artwork) {}

    /** @return list<array{string, string}> */
    public function assets(Model $design): array
    {
        if ($design instanceof BadgeDesignRevision || $design instanceof BadgeLibraryVersion) {
            return $this->snapshotAssets($design->snapshotSettings());
        }
        $assets = $design instanceof EventBadgeTemplate ? $this->snapshotAssets($design->backSettings() ?? []) : [];
        foreach ($design instanceof EventBadgeTemplate ? ['background', 'logo'] : ['background', 'signature'] as $type) {
            $disk = $design->getAttribute("{$type}_disk");
            $path = $design->getAttribute("{$type}_path");
            if (is_string($disk) && is_string($path)) {
                $assets[] = [$disk, $path];
            }
        }

        return $assets;
    }

    /** @param list<array{string, string}> $assets */
    public function afterDeletionCommit(Model $design, array $assets): void
    {
        $design->getConnection()->afterCommit(fn () => $this->deleteUnreferenced($assets));
    }

    /** @param list<array{string, string}> $assets */
    public function deleteUnreferenced(array $assets): void
    {
        foreach ($assets as [$disk, $path]) {
            if ($disk === 'badge-catalog') {
                continue;
            }
            $referenced = false;
            foreach ([BadgeLibraryVersion::class, BadgeDesignRevision::class] as $snapshotModel) {
                foreach (['snapshot', 'snapshot->back'] as $root) {
                    foreach (['background', 'logo'] as $type) {
                        if ($snapshotModel::query()->where($root.'->'.$type.'_disk', $disk)->where($root.'->'.$type.'_path', $path)->exists()) {
                            $referenced = true;
                            break 3;
                        }
                    }
                }
            }
            if ($referenced) {
                continue;
            }
            if (EventBadgeTemplate::withoutGlobalScopes()->where('back->background_disk', $disk)->where('back->background_path', $path)->exists()) {
                continue;
            }
            foreach ([EventCertificateTemplate::class, EventCertificateDesignVersion::class, EventBadgeTemplate::class] as $model) {
                foreach ($model === EventBadgeTemplate::class ? ['background', 'logo'] : ['background', 'signature'] as $type) {
                    if ($model::withoutGlobalScopes()->where("{$type}_disk", $disk)->where("{$type}_path", $path)->exists()) {
                        $referenced = true;
                        break 2;
                    }
                }
            }
            if (! $referenced) {
                $this->artwork->delete($disk, $path);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array{string, string}>
     */
    private function snapshotAssets(array $snapshot): array
    {
        $assets = [];
        foreach (['background', 'logo'] as $type) {
            if (is_string($snapshot[$type.'_disk'] ?? null) && is_string($snapshot[$type.'_path'] ?? null)) {
                $assets[] = [$snapshot[$type.'_disk'], $snapshot[$type.'_path']];
            }
        }
        if (is_array($snapshot['back'] ?? null)) {
            array_push($assets, ...$this->snapshotAssets($snapshot['back']));
        }

        return $assets;
    }
}
