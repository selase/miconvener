<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\VenueSettlementReserveFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Seller earnings from one rental charge, held back from payout until a staff
 * completion decision releases them. It is a hold on money already in the
 * ledger, never a second liability.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $shop_id
 * @property string $booking_id
 * @property string $charge_id
 * @property int $reserved_pesewas
 * @property string $currency
 * @property string $state
 * @property string|null $release_decision_id
 * @property \Illuminate\Support\Carbon|null $released_at
 */
final class VenueSettlementReserve extends Model
{
    /** @use HasFactory<VenueSettlementReserveFactory> */
    use HasFactory;

    use HasUuids;

    public const HELD = 'held';

    public const RELEASED = 'released';

    protected $connection = 'landlord';

    protected $table = 'venue_settlement_reserves';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'shop_id', 'booking_id', 'charge_id', 'reserved_pesewas', 'currency', 'state', 'release_decision_id', 'released_at'];

    /** @return BelongsTo<VenueBookingCharge, $this> */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(VenueBookingCharge::class, 'charge_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['reserved_pesewas' => 'integer', 'released_at' => 'datetime'];
    }
}
