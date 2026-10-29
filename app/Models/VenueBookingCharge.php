<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\VenueBookingChargeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One full payment owed under a venue contract, linked to the common reservation
 * order that carries its payment attempts. Payment state lives on the order.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $shop_id
 * @property string $booking_id
 * @property string|null $quote_id
 * @property string $order_id
 * @property string $purpose
 * @property int $amount_pesewas
 * @property int $fee_pesewas
 * @property \Illuminate\Support\Carbon|null $due_at
 */
final class VenueBookingCharge extends Model
{
    /** @use HasFactory<VenueBookingChargeFactory> */
    use HasFactory;

    use HasUuids;

    public const RENTAL_INITIAL = 'rental_initial';

    public const RENTAL_BALANCE = 'rental_balance';

    public const SECURITY = 'security';

    public const LEGACY_REVIEW = 'legacy_review';

    protected $connection = 'landlord';

    protected $table = 'venue_booking_charges';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'shop_id', 'booking_id', 'quote_id', 'order_id', 'purpose', 'amount_pesewas', 'fee_pesewas', 'due_at'];

    /** @return BelongsTo<VenueBooking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(VenueBooking::class, 'booking_id');
    }

    /** @return BelongsTo<VenueFinancialQuote, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(VenueFinancialQuote::class, 'quote_id');
    }

    /** @return BelongsTo<StoreOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class, 'order_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount_pesewas' => 'integer', 'fee_pesewas' => 'integer', 'due_at' => 'datetime'];
    }
}
