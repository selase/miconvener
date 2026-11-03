<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EventAttendeeProfile extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\EventAttendeeProfileFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = ['*'];

    protected $visible = ['id'];

    protected $casts = ['consented_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'withdrawn_at' => 'immutable_datetime', 'moderated_at' => 'immutable_datetime'];

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
