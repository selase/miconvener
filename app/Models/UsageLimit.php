<?php

declare(strict_types=1);

namespace App\Models;

use App\Enum\UsageMetric;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UsageLimit extends Model
{
    use HasFactory;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'metric',
        'limit_value',
        'period',
        'alert_threshold',
        'block_on_limit',
        'is_active',
        'last_alert_at',
    ];

    protected $casts = [
        'metric' => UsageMetric::class,
        'limit_value' => 'decimal:4',
        'is_active' => 'boolean',
        'block_on_limit' => 'boolean',
        'last_alert_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
