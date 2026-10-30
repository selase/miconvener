<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\VenueFinancialDecisionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An append-only outcome on a financial quote: the buyer's acceptance, the host's
 * withdrawal, or staff recording that the booked period was completed.
 *
 * @property string $id
 * @property string $quote_id
 * @property string $booking_id
 * @property string $shop_id
 * @property string $tenant_id
 * @property string $decision
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string|null $actor_name
 * @property string|null $buyer_email
 * @property string|null $reason
 * @property string|null $terms_hash
 * @property string $operation_key
 * @property string $request_hash
 * @property \Illuminate\Support\Carbon|null $created_at
 */
final class VenueFinancialDecision extends Model
{
    /** @use HasFactory<VenueFinancialDecisionFactory> */
    use HasFactory;

    use HasUuids;

    public const UPDATED_AT = null;

    public const ACCEPTED = 'accepted';

    public const WITHDRAWN = 'withdrawn';

    public const COMPLETED = 'completed';

    protected $connection = 'landlord';

    protected $table = 'venue_financial_decisions';

    /** @var list<string> */
    protected $fillable = ['quote_id', 'booking_id', 'shop_id', 'tenant_id', 'decision', 'actor_type', 'actor_id', 'actor_name', 'buyer_email', 'reason', 'terms_hash', 'operation_key', 'request_hash'];

    /** @return BelongsTo<VenueFinancialQuote, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(VenueFinancialQuote::class, 'quote_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['actor_id' => 'integer'];
    }
}
