<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $quote_reference
 * @property string $tenant_id
 * @property string $shop_id
 * @property string|null $store_listing_id
 * @property string|null $planner_tenant_id
 * @property string|null $event_id
 * @property string $planner_name
 * @property string $planner_email
 * @property string|null $planner_phone
 * @property string|null $event_title
 * @property Carbon|null $event_date
 * @property int|null $guest_count
 * @property string|null $location_address
 * @property string $requirements_description
 * @property string $status
 * @property array<int, array<string, mixed>>|null $items
 * @property int $subtotal_pesewas
 * @property int $delivery_fee_pesewas
 * @property int $tax_pesewas
 * @property int $total_amount_pesewas
 * @property int $deposit_required_pesewas
 * @property int $amount_paid_pesewas
 * @property Carbon|null $valid_until
 * @property string|null $vendor_notes
 * @property Carbon|null $accepted_at
 * @property string|null $paystack_reference
 * @property Carbon|null $paid_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant|null $tenant
 * @property-read Shop|null $shop
 * @property-read StoreListing|null $listing
 * @property-read Tenant|null $plannerTenant
 * @property-read Event|null $event
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MarketplaceReview> $reviews
 *
 * @method static Builder<self> query()
 * @method static self create(array<string, mixed> $attributes = [])
 */
final class MarketplaceQuote extends Model
{
    use HasUuids;

    public const string STATUS_PENDING_QUOTE = 'pending_quote';

    public const string STATUS_QUOTED = 'quoted';

    public const string STATUS_ACCEPTED = 'accepted';

    public const string STATUS_REJECTED = 'rejected';

    public const string STATUS_EXPIRED = 'expired';

    protected $connection = 'landlord';

    protected $fillable = [
        'quote_reference',
        'tenant_id',
        'shop_id',
        'store_listing_id',
        'planner_tenant_id',
        'event_id',
        'planner_name',
        'planner_email',
        'planner_phone',
        'event_title',
        'event_date',
        'guest_count',
        'location_address',
        'requirements_description',
        'status',
        'items',
        'subtotal_pesewas',
        'delivery_fee_pesewas',
        'tax_pesewas',
        'total_amount_pesewas',
        'deposit_required_pesewas',
        'buyer_fee_pesewas',
        'amount_paid_pesewas',
        'valid_until',
        'vendor_notes',
        'accepted_at',
        'paystack_reference',
        'paid_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'event_date' => 'date',
        'valid_until' => 'datetime',
        'accepted_at' => 'datetime',
        'paid_at' => 'datetime',
        'items' => 'array',
        'guest_count' => 'integer',
        'subtotal_pesewas' => 'integer',
        'delivery_fee_pesewas' => 'integer',
        'tax_pesewas' => 'integer',
        'total_amount_pesewas' => 'integer',
        'deposit_required_pesewas' => 'integer',
        'buyer_fee_pesewas' => 'integer',
        'amount_paid_pesewas' => 'integer',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * @return BelongsTo<StoreListing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(StoreListing::class, 'store_listing_id');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function plannerTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'planner_tenant_id');
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return HasMany<MarketplaceReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(MarketplaceReview::class);
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null && $this->amount_paid_pesewas > 0;
    }

    public function isDepositPaid(): bool
    {
        return $this->isPaid() && $this->amount_paid_pesewas >= $this->deposit_required_pesewas;
    }

    public function isExpired(): bool
    {
        if ($this->isAccepted() || $this->isPaid()) {
            return false;
        }

        return $this->valid_until !== null && $this->valid_until->isPast();
    }

    public function venuePaymentsPaused(): bool
    {
        $listing = $this->listing;

        return $listing !== null
            && $listing->listing_kind === StoreListing::KIND_VENUE
            && ! $listing->isBookable();
    }

    /**
     * The buyer's service fee as they will pay it: the figure fixed at
     * checkout once there is one, otherwise what their plan would charge on
     * the amount due now.
     *
     * @return array{service_fee_pesewas: int, service_fee_percent: float, formatted_service_fee: string, formatted_due_now: string}
     */
    public function serviceFeePayload(): array
    {
        $fees = app(\App\Services\Marketplace\MarketplaceFees::class);
        $dueNow = $this->deposit_required_pesewas > 0 ? $this->deposit_required_pesewas : $this->total_amount_pesewas;
        $fee = $this->buyer_fee_pesewas > 0 ? (int) $this->buyer_fee_pesewas : $fees->buyerFeeOn($this->plannerTenant, (int) $dueNow);

        return [
            'service_fee_pesewas' => $fee,
            'service_fee_percent' => $fees->buyerFeePercent($this->plannerTenant),
            'formatted_service_fee' => number_format($fee / 100, 2).' GHS',
            'formatted_due_now' => number_format(((int) $dueNow + $fee) / 100, 2).' GHS',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'quote_reference' => $this->quote_reference,
            'status' => $this->status,
            'planner_name' => $this->planner_name,
            'planner_email' => $this->planner_email,
            'planner_phone' => $this->planner_phone,
            'event_title' => $this->event_title,
            'event_date' => $this->event_date?->format('Y-m-d'),
            'guest_count' => $this->guest_count,
            'location_address' => $this->location_address,
            'requirements_description' => $this->requirements_description,
            'items' => $this->items ?? [],
            'subtotal_pesewas' => $this->subtotal_pesewas,
            'delivery_fee_pesewas' => $this->delivery_fee_pesewas,
            'tax_pesewas' => $this->tax_pesewas,
            'total_amount_pesewas' => $this->total_amount_pesewas,
            'deposit_required_pesewas' => $this->deposit_required_pesewas,
            'buyer_fee_pesewas' => $this->buyer_fee_pesewas,
            'amount_paid_pesewas' => $this->amount_paid_pesewas,
            'deposit_percentage' => $this->total_amount_pesewas > 0
                ? (int) round(($this->deposit_required_pesewas / $this->total_amount_pesewas) * 100)
                : 0,
            'formatted_subtotal' => number_format($this->subtotal_pesewas / 100, 2).' GHS',
            'formatted_delivery_fee' => number_format($this->delivery_fee_pesewas / 100, 2).' GHS',
            'formatted_tax' => number_format($this->tax_pesewas / 100, 2).' GHS',
            'formatted_total' => number_format($this->total_amount_pesewas / 100, 2).' GHS',
            'formatted_deposit' => number_format($this->deposit_required_pesewas / 100, 2).' GHS',
            ...$this->serviceFeePayload(),
            'formatted_balance_due' => number_format(max(0, $this->total_amount_pesewas - $this->deposit_required_pesewas) / 100, 2).' GHS',
            'formatted_amount_paid' => number_format($this->amount_paid_pesewas / 100, 2).' GHS',
            'valid_until' => $this->valid_until?->toIso8601String(),
            'formatted_valid_until' => $this->valid_until?->format('M d, Y h:i A'),
            'vendor_notes' => $this->vendor_notes,
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'paystack_reference' => $this->paystack_reference,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'is_expired' => $this->isExpired(),
            'venue_payments_paused' => $this->venuePaymentsPaused(),
            'is_deposit_paid' => $this->isDepositPaid(),
            'created_at' => $this->created_at?->toIso8601String(),
            'shop' => $this->relationLoaded('shop') && $this->shop ? [
                'name' => $this->shop->name,
                'slug' => $this->shop->slug,
                'email' => $this->shop->email,
                'phone' => $this->shop->phone,
            ] : null,
            'listing' => $this->relationLoaded('listing') && $this->listing ? [
                'id' => $this->listing->id,
                'title' => $this->listing->title,
                'slug' => $this->listing->slug,
                'category' => $this->listing->category,
            ] : null,
        ];
    }
}
