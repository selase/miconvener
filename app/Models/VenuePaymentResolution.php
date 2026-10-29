<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\VenuePaymentResolutionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An append-only staff step on one piece of payment conflict evidence. The
 * evidence itself is never edited; a refund or a confirmation ends the case.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $shop_id
 * @property string $booking_id
 * @property string $action_id
 * @property string $step
 * @property string $provider
 * @property string $account_fingerprint
 * @property string $reference
 * @property int|null $verified_amount_pesewas
 * @property string $currency
 * @property string|null $reason
 * @property int $actor_id
 * @property string $actor_name
 * @property string $operation_key
 * @property string $request_hash
 * @property array<string, mixed>|null $evidence
 * @property \Illuminate\Support\Carbon|null $created_at
 */
final class VenuePaymentResolution extends Model
{
    /** @use HasFactory<VenuePaymentResolutionFactory> */
    use HasFactory;

    use HasUuids;

    public const UPDATED_AT = null;

    public const VERIFIED = 'verified';

    public const RETAINED = 'retained';

    public const REFUNDED = 'refunded';

    public const CONFIRMED = 'confirmed';

    protected $connection = 'landlord';

    protected $table = 'venue_payment_resolutions';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'shop_id', 'booking_id', 'action_id', 'step', 'provider', 'account_fingerprint', 'reference', 'verified_amount_pesewas', 'currency', 'reason', 'actor_id', 'actor_name', 'operation_key', 'request_hash', 'evidence'];

    /** @return BelongsTo<VenueBookingAction, $this> */
    public function action(): BelongsTo
    {
        return $this->belongsTo(VenueBookingAction::class, 'action_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['verified_amount_pesewas' => 'integer', 'actor_id' => 'integer', 'evidence' => 'array'];
    }
}
