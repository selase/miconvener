<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasUuid;
use App\Traits\SpatieActivityLogs;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class Package extends Model
{
    use HasFactory;
    use HasUuid;
    use SpatieActivityLogs;

    public const string BILLING_MODEL_FLAT_RATE = 'flat_rate';

    public const string BILLING_MODEL_PER_SEAT = 'per_seat';

    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'price',
        'yearly_price',
        'interval',
        'billing_model',
        'description',
        'is_active',
        'is_free',
        'sort_order',
        'markup_percentage',
        'default_platform_fee_percentage',
        'default_platform_fee_cap_amount',
        'default_fee_bearer',
        'paystack_plan_code',
        'paystack_yearly_plan_code',
        'stripe_price_id',
        'stripe_yearly_price_id',
    ];

    protected $keyType = 'string';

    protected $casts = [
        'price' => 'decimal:2',
        'yearly_price' => 'decimal:2',
        'is_active' => 'boolean',
        'is_free' => 'boolean',
        'sort_order' => 'integer',
        'markup_percentage' => 'decimal:2',
        'default_platform_fee_percentage' => 'decimal:2',
        'default_platform_fee_cap_amount' => 'integer',
    ];

    public function isFree(): bool
    {
        return $this->is_free || (float) $this->price === 0.0;
    }

    /**
     * Get the payment provider plan code for a given billing interval.
     */
    public function getPlanCodeForInterval(string $interval = 'month'): ?string
    {
        $driver = config('services.payment.default', 'paystack');

        if ($driver === 'stripe') {
            return $interval === 'year' ? $this->stripe_yearly_price_id : $this->stripe_price_id;
        }

        return $interval === 'year' ? $this->paystack_yearly_plan_code : $this->paystack_plan_code;
    }

    /**
     * Get the price for a given billing interval.
     */
    public function getPriceForInterval(string $interval = 'month'): float
    {
        return $interval === 'year' ? (float) ($this->yearly_price ?? $this->price * 10) : (float) $this->price;
    }

    public function features()
    {
        return $this->belongsToMany(Feature::class, 'package_features')
            ->withPivot('value')
            ->withTimestamps();
    }

    public function usagePrices(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(UsagePrice::class, 'target');
    }
}
