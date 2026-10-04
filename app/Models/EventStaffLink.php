<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A staff link: what an usher or floor crew member opens on their phone to
 * scan tickets and answer attendee requests for one event. No account, no
 * console: it reaches only what its switches allow, and stops working when
 * revoked or once the event is over.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $event_id
 * @property string $name
 * @property string $token
 * @property string|null $pin_hash
 * @property bool $can_check_in
 * @property bool $can_handle_requests
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property-read Event|null $event
 */
final class EventStaffLink extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\EventStaffLinkFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * How long after the event ends a link keeps working, for late arrivals
     * and packing up.
     */
    public const int HOURS_AFTER_EVENT = 12;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'name',
        'token',
        'pin_hash',
        'can_check_in',
        'can_handle_requests',
        'created_by',
        'last_used_at',
        'revoked_at',
    ];

    protected $hidden = [
        'pin_hash',
    ];

    protected $casts = [
        'can_check_in' => 'boolean',
        'can_handle_requests' => 'boolean',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public static function newToken(): string
    {
        return Str::random(48);
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Links that still count against the tenant's allowance: not revoked, and
     * their event not yet over.
     *
     * @param  Builder<EventStaffLink>  $query
     * @return Builder<EventStaffLink>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->whereHas('event', fn (Builder $event): Builder => $event->where('ends_at', '>', now()->subHours(self::HOURS_AFTER_EVENT)));
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && $this->event !== null
            && $this->event->ends_at->copy()->addHours(self::HOURS_AFTER_EVENT)->isFuture();
    }

    public function requiresPin(): bool
    {
        return $this->pin_hash !== null;
    }

    public function pinMatches(string $pin): bool
    {
        return $this->pin_hash !== null && Hash::check($pin, $this->pin_hash);
    }

    public function setPin(?string $pin): void
    {
        $this->pin_hash = filled($pin) ? Hash::make($pin) : null;
    }

    public function url(): string
    {
        return route('public.staff.show', [
            'subdomain' => $this->event->tenant->slug,
            'token' => $this->token,
        ]);
    }
}
