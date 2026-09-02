<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Tenancy\TenantContext;
use App\Traits\HasUuid;
use App\Traits\SpatieActivityLogs;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TenantApiKey extends Model
{
    use HasFactory;
    use HasUuid;
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;
    use SpatieActivityLogs;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $casts = [
        'scopes' => 'array',
        'ip_restrictions' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isValid(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        self::creating(function ($model): void {
            if (! $model->tenant_id && $tenant = app(TenantContext::class)->getTenant()) {
                $model->tenant_id = $tenant->id;
            }
        });
    }
}
