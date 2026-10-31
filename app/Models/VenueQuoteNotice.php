<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\VenueQuoteNoticeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The durable record that the buyer is told about a quote: one row per quote
 * and kind, claimed before sending, retried with a back-off when sending fails,
 * and visible to the host so a buyer who never got the email can be contacted.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $shop_id
 * @property string $booking_id
 * @property string $quote_id
 * @property string $kind
 * @property string $state
 * @property int $attempts
 * @property \Illuminate\Support\Carbon|null $next_attempt_at
 * @property \Illuminate\Support\Carbon|null $claimed_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property string|null $error
 */
final class VenueQuoteNotice extends Model
{
    /** @use HasFactory<VenueQuoteNoticeFactory> */
    use HasFactory;

    use HasUuids;

    public const ISSUED = 'issued';

    public const WITHDRAWN = 'withdrawn';

    public const QUEUED = 'queued';

    public const SENDING = 'sending';

    public const RETRY = 'retry';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const MAX_ATTEMPTS = 4;

    protected $connection = 'landlord';

    protected $table = 'venue_quote_notices';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'shop_id', 'booking_id', 'quote_id', 'kind', 'state', 'attempts', 'next_attempt_at', 'claimed_at', 'completed_at', 'error'];

    /** @return BelongsTo<VenueFinancialQuote, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(VenueFinancialQuote::class, 'quote_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['attempts' => 'integer', 'next_attempt_at' => 'datetime', 'claimed_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
