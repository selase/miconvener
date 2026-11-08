<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class EventAttendeeImportInvitation extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $hidden = ['recipient'];

    protected $casts = ['recipient' => 'encrypted', 'claimed_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime'];
}
