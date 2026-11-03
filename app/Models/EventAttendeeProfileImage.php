<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $event_id
 * @property string $registration_id
 * @property string $owner_hash
 * @property string $disk
 * @property string $path
 * @property string|null $profile_id
 * @property int $byte_size
 * @property \Carbon\CarbonImmutable $expires_at
 * @property \Carbon\CarbonImmutable|null $retired_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 */
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
