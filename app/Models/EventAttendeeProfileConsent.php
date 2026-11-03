<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class EventAttendeeProfileConsent extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\EventAttendeeProfileConsentFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    protected $connection = 'landlord';

    protected $guarded = ['*'];

    protected $visible = ['id'];

    protected $casts = ['occurred_at' => 'immutable_datetime', 'retain_until' => 'immutable_datetime'];
}
