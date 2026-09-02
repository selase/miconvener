<?php

declare(strict_types=1);

namespace App\Models;

use App\Enum\UsageMetric;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class UsageEvent extends Model
{
    use HasFactory;
    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'datetime',
        'type' => UsageMetric::class,
        'quantity' => 'decimal:4',
        'meta' => 'array',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
