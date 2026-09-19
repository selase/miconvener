<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WebhookEndpoint extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected $casts = [
        'events' => 'array',
        'secret' => 'encrypted',
        'is_active' => 'boolean',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return HasMany<WebhookCall, $this>
     */
    public function calls(): HasMany
    {
        return $this->hasMany(WebhookCall::class);
    }
}
