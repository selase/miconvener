<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Support\Str;
use Ramsey\Uuid\UuidInterface;

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

    public static function generateUuid(): UuidInterface
    {
        return Str::uuid();
    }

    public static function findByUuid(string $uuid): ?self
    {
        return static::where('uuid', $uuid)->first();
    }

    /**
     * As findByUuid(), but a missing record is a 404 rather than a null that
     * fails later with a 500.
     */
    public static function findByUuidOrFail(string $uuid): self
    {
        return static::where('uuid', $uuid)->firstOrFail();
    }
}
