<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class EventTicketType extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const string TIER_GENERAL = 'general';

    public const string TIER_SPEAKER = 'speaker';

    public const string TIER_VIP = 'vip';

    public const string TIER_STAFF = 'staff';

    public const array TIERS = [
        self::TIER_GENERAL,
        self::TIER_SPEAKER,
        self::TIER_VIP,
        self::TIER_STAFF,
    ];

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'name',
        'description',
        'badge_tier',
        'price',
        'capacity',
        'is_active',
        'access_code',
        'sort_order',
    ];

    protected $casts = [
        'price' => 'integer',
        'capacity' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function isInviteOnly(): bool
    {
        return ! empty($this->access_code);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class, 'ticket_type_id');
    }

    public function isFree(): bool
    {
        return $this->price <= 0;
    }

    public function confirmedCount(): int
    {
        return $this->registrations()->confirmed()->count();
    }

    public function isSoldOut(): bool
    {
        return $this->capacity !== null && $this->confirmedCount() >= $this->capacity;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
