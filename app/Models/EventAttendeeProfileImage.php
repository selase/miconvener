<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class EventAttendeeProfileImage extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\EventAttendeeProfileImageFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = ['*'];

    protected $visible = ['id'];

    protected $casts = ['byte_size' => 'integer', 'expires_at' => 'immutable_datetime', 'retired_at' => 'immutable_datetime', 'deleted_at' => 'immutable_datetime'];
}
