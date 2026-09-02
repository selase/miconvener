<?php

declare(strict_types=1);

namespace App\Models;

use App\Enum\UsageMetric;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class UsagePrice extends Model
{
    use HasFactory;

    protected $connection = 'landlord';

    protected $fillable = [
        'target_type',
        'target_id',
        'metric',
        'unit_price',
        'unit_quantity',
        'currency',
    ];

    protected $casts = [
        'metric' => UsageMetric::class,
        'unit_price' => 'decimal:6',
        'unit_quantity' => 'decimal:4',
    ];

    public function target(): MorphTo
    {
        return $this->morphTo();
    }
}
