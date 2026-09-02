<?php

declare(strict_types=1);

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool enabled(string $key)
 * @method static void enable(string $key)
 * @method static void disable(string $key)
 *
 * @see \App\Services\Tenancy\FeatureService
 */
final class Feature extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'Feature';
    }
}
