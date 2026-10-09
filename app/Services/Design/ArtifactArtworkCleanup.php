<?php

declare(strict_types=1);

namespace App\Services\Design;

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
        $assets = [];
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
            $referenced = false;
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
}
