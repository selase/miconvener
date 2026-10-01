<?php

declare(strict_types=1);

namespace App\Services\Certificates;

use App\Models\EventCertificateDesignVersion;
use App\Models\EventCertificateTemplate;
use App\Services\Design\ArtifactArtworkService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class CertificateDesignVersionService
{
    /** @var list<string> */
    private const array SNAPSHOT_FIELDS = [
        'design_mode',
        'orientation',
        'page_size',
        'title',
        'body_template',
        'issuer_name',
        'issuer_title',
        'signature_disk',
        'signature_path',
        'background_disk',
        'background_path',
        'show_qr',
        'show_cpd_hours',
        'default_cpd_hours',
        'layout',
    ];

    public function __construct(private readonly ArtifactArtworkService $artwork) {}

    public function snapshot(EventCertificateTemplate $template): EventCertificateDesignVersion
    {
        return DB::connection('landlord')->transaction(function () use ($template): EventCertificateDesignVersion {
            $locked = EventCertificateTemplate::query()->lockForUpdate()->findOrFail($template->getKey());
            $payload = Arr::only($locked->getAttributes(), self::SNAPSHOT_FIELDS);
            $payload['layout'] = $locked->layout;
            $payload['show_qr'] = (bool) $locked->show_qr;
            $payload['show_cpd_hours'] = (bool) $locked->show_cpd_hours;
            $payload['default_cpd_hours'] = (float) $locked->default_cpd_hours;
            $canonical = $this->canonicalize($payload);
            $hash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE));

            $existing = $locked->designVersions()->where('design_hash', $hash)->first();

            if ($existing instanceof EventCertificateDesignVersion) {
                return $existing;
            }

            $nextVersion = ((int) $locked->designVersions()->max('version')) + 1;

            return $locked->designVersions()->create($payload + [
                'tenant_id' => $locked->tenant_id,
                'event_id' => $locked->event_id,
                'version' => $nextVersion,
                'design_hash' => $hash,
            ]);
        }, 3);
    }

    public function deleteIfUnreferenced(EventCertificateDesignVersion $version): bool
    {
        $assets = DB::connection('landlord')->transaction(function () use ($version): array|false {
            $locked = EventCertificateDesignVersion::query()->lockForUpdate()->find($version->getKey());

            if (! $locked instanceof EventCertificateDesignVersion || $locked->certificates()->exists()) {
                return false;
            }

            $assets = [
                [$locked->background_disk, $locked->background_path, 'background'],
                [$locked->signature_disk, $locked->signature_path, 'signature'],
            ];

            $locked->delete();

            return array_values(array_filter(
                $assets,
                fn (array $asset): bool => is_string($asset[0])
                    && is_string($asset[1])
                    && ! $this->assetIsReferenced($asset[0], $asset[1], $asset[2]),
            ));
        });

        if ($assets === false) {
            return false;
        }

        foreach ($assets as [$disk, $path]) {
            $this->artwork->delete($disk, $path);
        }

        return true;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function assetIsReferenced(string $disk, string $path, string $type): bool
    {
        $diskColumn = "{$type}_disk";
        $pathColumn = "{$type}_path";

        return EventCertificateTemplate::withoutGlobalScopes()
            ->where($diskColumn, $disk)
            ->where($pathColumn, $path)
            ->exists()
            || EventCertificateDesignVersion::withoutGlobalScopes()
                ->where($diskColumn, $disk)
                ->where($pathColumn, $path)
                ->exists();
    }
}
