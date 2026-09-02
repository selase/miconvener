<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Tenancy\TenantContext;
use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LlmTokenUsage extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuid;
    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $casts = [
        'context' => 'array',
        'cost_usd' => 'decimal:6',
        'total_tokens' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(TenantApiKey::class);
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
