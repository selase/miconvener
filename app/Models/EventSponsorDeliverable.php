<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EventSponsorDeliverable extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'sponsor_id',
        'description',
        'is_done',
    ];

    protected $casts = [
        'is_done' => 'boolean',
    ];

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(EventSponsor::class, 'sponsor_id');
    }
}
