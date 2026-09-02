<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class TenantFeatureUsage extends Model
{
    use HasFactory;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $casts = [
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'used_count' => 'integer',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeCurrentPeriod($query)
    {
        // For simple implementations, we assume null period = lifetime usage.
        // For cyclical usage, we check date ranges.
        return $query->where(function ($q): void {
            $now = now();
            $q->whereNull('period_start')
                ->orWhere(function ($q2) use ($now): void {
                    $q2->where('period_start', '<=', $now)
                        ->where('period_end', '>=', $now);
                });
        });
    }
}
