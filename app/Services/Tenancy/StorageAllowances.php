<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Feature;
use App\Models\Package;
use App\Models\Tenant;
use LogicException;

final class StorageAllowances
{
    public const array DEFAULTS = ['free' => 104857600, 'starter' => 1073741824, 'growth' => 5368709120, 'enterprise' => 21474836480];

    /** Called by the policy migration before any default is installed. */
    public function preserveExisting(): void
    {
        foreach (Tenant::query()->whereNull('storage_limit_snapshot')->lazyById() as $tenant) {
            $tenant->timestamps = false;
            $tenant->forceFill(['storage_limit_snapshot' => $tenant->planLimitValue('storage_bytes') ?? -1])->saveQuietly();
        }
    }

    /** Installs only missing storage pivots, never package prices or other terms. */
    public function installDefaults(): void
    {
        $feature = Feature::query()->firstOrCreate(['slug' => 'storage_bytes'], ['name' => 'Library file storage', 'type' => 'limit', 'description' => 'Shared allowance for new Library and merchandise-media uploads, including retained editions.']);
        if ($feature->type !== 'limit') {
            throw new LogicException('Review the existing storage feature type before applying allowances.');
        }
        foreach (Package::query()->whereIn('slug', array_keys(self::DEFAULTS))->get() as $package) {
            if (! $package->features()->where('features.id', $feature->id)->exists()) {
                $package->features()->attach($feature->id, ['value' => self::DEFAULTS[$package->slug]]);
            }
        }
    }

    public function limit(Tenant $tenant): ?int
    {
        if ($tenant->storage_limit_snapshot !== null) {
            return $tenant->storage_limit_snapshot < 0 ? null : $tenant->storage_limit_snapshot;
        }
        if ($tenant->features()->where('feature_key', 'storage_bytes')->exists()) {
            return $tenant->planLimitValue('storage_bytes');
        }
        $package = $tenant->package()->with('features')->first();
        $feature = $package?->features->firstWhere('slug', 'storage_bytes');
        if ($feature !== null && $feature->type === 'limit') {
            /** @var \Illuminate\Database\Eloquent\Relations\Pivot $pivot */
            $pivot = $feature->getRelation('pivot');
            $limit = (int) $pivot->getAttribute('value');

            return $limit < 0 ? null : $limit;
        }

        return self::DEFAULTS[$package->slug ?? 'free'] ?? null;
    }
}
