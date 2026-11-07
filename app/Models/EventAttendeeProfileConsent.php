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
 * @property string $profile_id
 * @property string $subject_hash
 * @property string $action
 * @property string|null $revision
 * @property string|null $consent_version
 * @property string|null $reason
 * @property int|null $actor_id
 * @property \Carbon\CarbonImmutable $occurred_at
 * @property \Carbon\CarbonImmutable $retain_until
 */
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
