<?php

declare(strict_types=1);

namespace App\Models;

use App\Enum\UsageMetric;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class UsageRollup extends Model
{
    use HasFactory;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $casts = [
        'period_start' => 'datetime',
        'metric' => UsageMetric::class,
        'dimensions' => 'array',
        'value' => 'decimal:4',
    ];

    public static function hashDimensions(?array $dimensions): string
    {
        if ($dimensions === null || $dimensions === []) {
            return 'empty';
        }

        ksort($dimensions);

        return md5(json_encode($dimensions));
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    protected static function booted(): void
    {
        self::saving(function ($rollup): void {
            $rollup->dimensions_hash = self::hashDimensions($rollup->dimensions);
        });
    }
}
