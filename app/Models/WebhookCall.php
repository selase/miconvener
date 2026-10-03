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
    use HasFactory;
    use HasUuids;
    use SpatieActivityLogs;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'webhook_endpoint_id',
        'event_name',
        'payload',
        'status',
        'duration_ms',
        'response',
        'exception',
    ];

    protected $casts = [
        'payload' => 'array',
        'status' => 'integer',
        'duration_ms' => 'integer',
    ];

    public function isSuccessful(): bool
    {
        return $this->status !== null && $this->status >= 200 && $this->status < 300;
    }

    public function isPending(): bool
    {
        return $this->status === null && $this->exception === null;
    }

    public function isFailed(): bool
    {
        return $this->exception !== null || ($this->status !== null && $this->status >= 400);
    }

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
