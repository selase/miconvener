<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $booking_reference
 * @property string $tenant_id
 * @property string $shop_id
 * @property string $store_listing_id
 * @property int|null $user_id
 * @property string|null $planner_tenant_id
 * @property string $planner_name
 * @property string $planner_email
 * @property string|null $planner_phone
 * @property string|null $planner_company
 * @property string $event_type
 * @property int $guest_count
 * @property string $layout_style
 * @property string|null $special_requests
 * @property \Illuminate\Support\Carbon $starts_at
 * @property \Illuminate\Support\Carbon $ends_at
 * @property string $time_slot_type
 * @property string $pricing_model
 * @property int $rate_pesewas
 * @property float $duration_units
 * @property int $rental_amount_pesewas
 * @property int $security_deposit_pesewas
 * @property int $total_amount_pesewas
 * @property int $deposit_required_pesewas
 * @property int $amount_paid_pesewas
 * @property int $gateway_fee_pesewas
 * @property string $status
 * @property string $payment_status
 * @property string|null $paystack_reference
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property \Illuminate\Support\Carbon|null $contract_agreed_at
 * @property array<string, mixed>|null $contract_terms_snapshot
 * @property string|null $host_notes
 * @property \Illuminate\Support\Carbon|null $quote_valid_until
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Tenant|null $tenant
 * @property-read Shop|null $shop
 * @property-read StoreListing|null $listing
 * @property-read User|null $user
 * @property-read Tenant|null $plannerTenant
 *
 * @method static Builder<self> query()
 * @method static Builder<self> where(string|array<string, mixed>|\Closure $column, mixed $operator = null, mixed $value = null, string $boolean = 'and')
 * @method static self create(array<string, mixed> $attributes = [])
 * @method static self firstOrFail(array<int, string>|string $columns = ['*'])
 * @method static Builder<self> blockingCalendar()
 * @method static Builder<self> overlapping(CarbonInterface $startsAt, CarbonInterface $endsAt)
 */
final class VenueBooking extends Model
{
    use HasUuids;

    public const STATUS_PENDING_QUOTE = 'pending_quote';

    public const STATUS_PENDING_PAYMENT = 'pending_payment';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_BLOCKED = 'blocked';

    public const PAYMENT_UNPAID = 'unpaid';

    public const PAYMENT_DEPOSIT_PAID = 'deposit_paid';

    public const PAYMENT_FULLY_PAID = 'fully_paid';

    public const PAYMENT_REFUNDED = 'refunded';

    public const SLOT_HOURLY = 'hourly';

    public const SLOT_FULL_DAY = 'full_day';

    public const SLOT_MULTI_DAY = 'multi_day';

    /**
     * @var string
     */
    protected $connection = 'landlord';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'booking_reference',
        'tenant_id',
        'shop_id',
        'store_listing_id',
        'user_id',
        'planner_tenant_id',
        'planner_name',
        'planner_email',
        'planner_phone',
        'planner_company',
        'event_type',
        'guest_count',
        'layout_style',
        'special_requests',
        'starts_at',
        'ends_at',
        'time_slot_type',
        'pricing_model',
        'rate_pesewas',
        'duration_units',
        'rental_amount_pesewas',
        'security_deposit_pesewas',
        'total_amount_pesewas',
        'deposit_required_pesewas',
        'amount_paid_pesewas',
        'gateway_fee_pesewas',
        'status',
        'payment_status',
        'paystack_reference',
        'paid_at',
        'contract_agreed_at',
        'contract_terms_snapshot',
        'host_notes',
        'quote_valid_until',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    /**
     * @return BelongsTo<StoreListing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(StoreListing::class, 'store_listing_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function plannerTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'planner_tenant_id');
    }

    /**
     * Scope bookings that currently block the venue calendar.
     *
     * Confirmed bookings always block. Pending payment bookings block only while
     * their quote/hold TTL is unexpired.
     *
     * @param  Builder<self>  $query
     */
    public function scopeBlockingCalendar(Builder $query): void
    {
        $query->where(function (Builder $q): void {
            $q->whereIn('status', [self::STATUS_CONFIRMED, self::STATUS_BLOCKED])
                ->orWhere(function (Builder $pending): void {
                    $pending->where('status', self::STATUS_PENDING_PAYMENT)
                        ->where(function (Builder $valid): void {
                            $valid->whereNull('quote_valid_until')
                                ->orWhere('quote_valid_until', '>=', now());
                        });
                });
        });
    }

    /**
     * Scope bookings that temporally overlap with the given window.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $startsAt, CarbonInterface $endsAt): void
    {
        $query->where(function (Builder $q) use ($startsAt, $endsAt): void {
            $q->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt);
        });
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }

    public function isDepositPaid(): bool
    {
        return in_array($this->payment_status, [self::PAYMENT_DEPOSIT_PAID, self::PAYMENT_FULLY_PAID], true);
    }

    public function isExpired(): bool
    {
        return $this->quote_valid_until !== null && $this->quote_valid_until->isPast();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'paid_at' => 'datetime',
            'contract_agreed_at' => 'datetime',
            'quote_valid_until' => 'datetime',
            'contract_terms_snapshot' => 'array',
            'guest_count' => 'integer',
            'duration_units' => 'float',
            'rate_pesewas' => 'integer',
            'rental_amount_pesewas' => 'integer',
            'security_deposit_pesewas' => 'integer',
            'total_amount_pesewas' => 'integer',
            'deposit_required_pesewas' => 'integer',
            'amount_paid_pesewas' => 'integer',
            'gateway_fee_pesewas' => 'integer',
        ];
    }
}
