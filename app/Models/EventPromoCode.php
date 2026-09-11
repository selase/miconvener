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

final class EventPromoCode extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const string TYPE_PERCENTAGE = 'percentage';

    public const string TYPE_FIXED = 'fixed_amount';

    public const string TYPE_COMPLIMENTARY = 'complimentary';

    public const array TYPES = [
        self::TYPE_PERCENTAGE,
        self::TYPE_FIXED,
        self::TYPE_COMPLIMENTARY,
    ];

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'code',
        'description',
        'discount_type',
        'discount_value',
        'currency',
        'max_redemptions',
        'redemptions_count',
        'max_per_attendee',
        'starts_at',
        'expires_at',
        'applicable_ticket_type_ids',
        'is_active',
    ];

    protected $casts = [
        'discount_value' => 'integer',
        'max_redemptions' => 'integer',
        'redemptions_count' => 'integer',
        'max_per_attendee' => 'integer',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'applicable_ticket_type_ids' => 'array',
        'is_active' => 'boolean',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class, 'promo_code_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeValid(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()))
            ->where(fn (Builder $q) => $q->whereNull('max_redemptions')->orWhereColumn('redemptions_count', '<', 'max_redemptions'));
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function hasStarted(): bool
    {
        return $this->starts_at === null || $this->starts_at->isPast();
    }

    public function isRedemptionLimitReached(): bool
    {
        return $this->max_redemptions !== null && $this->redemptions_count >= $this->max_redemptions;
    }

    public function isValidForTicketType(?EventTicketType $ticketType): bool
    {
        if (empty($this->applicable_ticket_type_ids)) {
            return true;
        }

        if (! $ticketType) {
            return false;
        }

        return in_array($ticketType->id, $this->applicable_ticket_type_ids, true);
    }

    public function calculateDiscount(int $amount): int
    {
        if ($amount <= 0) {
            return 0;
        }

        return match ($this->discount_type) {
            self::TYPE_COMPLIMENTARY => $amount,
            self::TYPE_PERCENTAGE => (int) round(($amount * min(100, max(0, $this->discount_value))) / 100),
            self::TYPE_FIXED => min($amount, max(0, $this->discount_value)),
            default => 0,
        };
    }

    public function incrementRedemptions(): void
    {
        $this->increment('redemptions_count');
    }
}
