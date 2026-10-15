<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** @property array{usage_id: int|null, usage_quantity: int, packs: array<string, int>} $sources */
final class PrepaidCreditReceipt extends Model
{
    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $casts = ['sources' => 'array', 'refunded_at' => 'datetime'];
}
