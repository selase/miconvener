<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\SpatieActivityLogs;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class WebhookCall extends Model
{
    use BelongsToTenant;
    use HasFactory, HasUuids;
    use SpatieActivityLogs;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
    ];

    public function endpoint()
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
