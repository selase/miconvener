<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class EventAttendeeImportRow extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $casts = ['data' => 'encrypted:array', 'errors' => 'array', 'selected' => 'boolean'];
}
