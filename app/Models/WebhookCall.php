<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\SpatieActivityLogs;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WebhookCall extends Model
{
    use BelongsToTenant;
    use HasFactory, HasUuids;
    use SpatieActivityLogs;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
    ];

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
