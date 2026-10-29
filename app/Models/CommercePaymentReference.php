<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CommercePaymentReferenceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who owns a provider payment reference. This is a registry, not a ledger: it
 * records the single source a reference was claimed for and, once, the ledger
 * entry that later posted it. It holds no secrets.
 *
 * @property string $id
 * @property string $provider
 * @property string $account_fingerprint
 * @property string $reference
 * @property string $tenant_id
 * @property string|null $shop_id
 * @property string $source_type
 * @property string|null $order_id
 * @property string|null $booking_id
 * @property string|null $ledger_reference
 * @property string $origin
 */
final class CommercePaymentReference extends Model
{
    /** @use HasFactory<CommercePaymentReferenceFactory> */
    use HasFactory;

    use HasUuids;

    public const SOURCE_ORDER = 'store_order';

    public const SOURCE_LEGACY_BOOKING = 'legacy_venue_booking';

    public const SOURCE_LEGACY_LEDGER = 'legacy_ledger_posting';

    protected $connection = 'landlord';

    protected $table = 'commerce_payment_references';

    /** @var list<string> */
    protected $fillable = ['provider', 'account_fingerprint', 'reference', 'tenant_id', 'shop_id', 'source_type', 'order_id', 'booking_id', 'ledger_reference', 'origin'];

    /** @return BelongsTo<StoreOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class, 'order_id');
    }

    /** @return BelongsTo<VenueBooking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(VenueBooking::class, 'booking_id');
    }
}
