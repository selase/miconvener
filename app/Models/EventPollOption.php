<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class EventPollOption extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'poll_id',
        'label',
        'sort_order',
        'is_correct',
    ];

    protected $casts = [
        'is_correct' => 'boolean',
    ];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(EventPoll::class, 'poll_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(EventPollResponse::class, 'option_id');
    }
}
