<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Subscription extends Model
{
    use HasFactory;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_PAST_DUE = 'past_due';

    public const string STATUS_CANCELLED = 'cancelled';

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'name',
        'provider_id',
        'provider_status',
        'provider_plan',
        'quantity',
        'trial_ends_at',
        'ends_at',
        'current_period_end',
        'pending_package_id',
        'interval',
        'authorization_code',
        'authorization_reusable',
        'authorization_email',
        'authorization_label',
        'grace_ends_at',
        'renewal_attempts',
        'renewal_attempted_at',
    ];

    /**
     * The Paystack authorization code charges the customer's saved payment
     * method, so it is encrypted at rest and hidden from serialization.
     *
     * @var list<string>
     */
    protected $hidden = ['authorization_code'];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'ends_at' => 'datetime',
        'current_period_end' => 'datetime',
        'grace_ends_at' => 'datetime',
        'authorization_code' => 'encrypted',
        'authorization_reusable' => 'boolean',
        'renewal_attempts' => 'integer',
        'renewal_attempted_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function pendingPackage(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'pending_package_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('provider_status', 'active');
    }

    public function isActive(): bool
    {
        return $this->provider_status === 'active';
    }

    public function hasPendingChange(): bool
    {
        return $this->pending_package_id !== null;
    }

    public function isPastDue(): bool
    {
        return $this->provider_status === self::STATUS_PAST_DUE;
    }

    /**
     * Whether the renewal can be charged without the customer: Paystack marks
     * an authorization reusable when the payment method allows it.
     */
    public function canBeChargedAutomatically(): bool
    {
        return $this->authorization_reusable && filled($this->authorization_code) && filled($this->authorization_email);
    }

    public function billingInterval(): string
    {
        if (in_array($this->interval, ['month', 'year'], true)) {
            return $this->interval;
        }

        return str_ends_with((string) $this->provider_plan, '_year') ? 'year' : 'month';
    }

    /**
     * What one period of this subscription costs on the given package now.
     */
    public function priceMinorFor(Package $package): int
    {
        $price = $this->billingInterval() === 'year'
            ? ($package->yearly_price ?? (float) $package->price * 10)
            : $package->price;

        return (int) round((float) $price * 100);
    }

    public function onGracePeriod(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isFuture();
    }
}
