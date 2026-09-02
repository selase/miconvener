<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Subscription extends Model
{
    use HasFactory;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'name',
        'provider_id',
        'provider_status',
        'provider_plan',
        'quantity',
        'trial_ends_at',
        'ends_at',
        'current_period_end',
        'pending_package_id',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'ends_at' => 'datetime',
        'current_period_end' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function pendingPackage(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'pending_package_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('provider_status', 'active');
    }

    public function isActive(): bool
    {
        return $this->provider_status === 'active';
    }

    public function hasPendingChange(): bool
    {
        return $this->pending_package_id !== null;
    }

    public function onGracePeriod(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isFuture();
    }
}
