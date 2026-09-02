<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Support\Str;

trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(function ($model): void {
            $uuid = self::generateUuid();
            while (self::where('uuid', $uuid)->count() > 0) {
                $uuid = self::generateUuid();
            }
            $model->uuid = $uuid;
        });
    }

    public static function generateUuid()
    {
        return Str::uuid();
    }

    public static function findByUuid(string $uuid): ?self
    {
        return static::where('uuid', $uuid)->first();
    }
}
