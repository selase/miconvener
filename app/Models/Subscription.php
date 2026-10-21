<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Pricing\ChargeQuote;
use App\Services\Pricing\GhanaEligibility;
use App\Services\Pricing\Money;
use App\Services\Pricing\PriceBook;
use App\Services\Pricing\Rate;
use Carbon\CarbonImmutable;
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
        'billing_currency',
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
     * What the next period of this plan costs, in GHS minor units (what
     * Paystack charges): the organisation's agreed price when it has one
     * (Enterprise), otherwise the plan's list price for this subscription's
     * interval in its billing currency, a USD price converted at today's rate.
     */
    public function priceMinorFor(Package $package): int
    {
        return $this->chargeQuoteFor($package)->ghsMinor();
    }

    /**
     * The price of the next period and what it is charged as, with the rate.
     */
    public function chargeQuoteFor(Package $package): ChargeQuote
    {
        $agreed = $this->tenant?->agreedPriceFor($package);
        $price = $agreed !== null
            ? new Money($agreed['amount'], 'GHS')
            : app(PriceBook::class)->plan($package, $this->billingInterval(), $this->renewalCurrency());

        return app(ChargeQuote::class)->for($price);
    }

    /**
     * The currency the next period is priced in: where the organisation
     * stands today. Ghana eligible organisations renew at the Ghana price,
     * those settled in USD at the USD price converted on the renewal day, and
     * one nobody has classified yet keeps the currency it was on.
     */
    public function renewalCurrency(): string
    {
        if ($this->tenant !== null) {
            if (app(GhanaEligibility::class)->ensure($this->tenant)) {
                return 'GHS';
            }

            if ($this->tenant->pricing_region === GhanaEligibility::REGION_INTERNATIONAL) {
                return 'USD';
            }
        }

        return (string) ($this->billing_currency ?: 'GHS');
    }

    /**
     * For a renewal priced in USD: what it costs, that it is charged in cedis
     * at the rate on the day, and today's figure. Null when the price is in GHS.
     */
    public function renewalNote(Package $package): ?string
    {
        $quote = $this->chargeQuoteFor($package);
        $meta = $quote->meta();

        if ($meta['display_currency'] !== 'USD' || $meta['fx_rate'] === null) {
            return null;
        }

        $note = sprintf(
            'This plan is priced in US dollars (%s). It is charged in Ghana cedis at the Bank of Ghana rate on the day it is paid; today that is %s at %s (%s).',
            $quote->price()->format(),
            $quote->charged()->format(),
            Rate::describe((float) $meta['fx_rate']),
            CarbonImmutable::parse((string) $meta['fx_as_of'])->format('j M Y'),
        );

        return ($this->billing_currency ?: 'GHS') === 'GHS'
            ? $note.' Your plan was priced in Ghana cedis before; from now on it is priced in US dollars.'
            : $note;
    }

    public function onGracePeriod(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isFuture();
    }
}
