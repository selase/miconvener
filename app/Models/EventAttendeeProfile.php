<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $event_id
 * @property string $registration_id
 * @property string $owner_hash
 * @property string $state
 * @property string|null $display_name
 * @property string|null $revision
 * @property string|null $feature_generation
 * @property string|null $tenant_generation
 * @property string|null $event_generation
 * @property string|null $registration_generation
 * @property string|null $image_id
 * @property string|null $operation_key
 * @property string|null $input_hash
 * @property string|null $consent_version
 * @property bool $adult_confirmed
 * @property \Carbon\CarbonImmutable|null $consented_at
 * @property \Carbon\CarbonImmutable|null $expires_at
 * @property \Carbon\CarbonImmutable|null $withdrawn_at
 * @property \Carbon\CarbonImmutable|null $moderated_at
 */
final class EventAttendeeProfile extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\EventAttendeeProfileFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = ['*'];

    protected $visible = ['id'];

    protected $casts = ['adult_confirmed' => 'boolean', 'consented_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'withdrawn_at' => 'immutable_datetime', 'moderated_at' => 'immutable_datetime'];

    /** @return BelongsTo<EventRegistration,$this> */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'registration_id')->withoutGlobalScopes();
    }

    /** @return BelongsTo<Event,$this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class)->withoutGlobalScopes()->withTrashed();
    }
}
