<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class MessageOutcomeResolution extends Model
{
    /** @use HasFactory<\Database\Factories\MessageOutcomeResolutionFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $casts = ['old_state' => 'array', 'new_state' => 'array', 'resolved_by' => 'integer'];
}
