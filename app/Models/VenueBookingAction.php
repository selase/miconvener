<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\VenueBookingActionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable evidence of one venue booking transition. Only the delivery
 * columns may change after creation; the database enforces it.
 *
 * @property string $id
 * @property string $booking_id
 * @property string $shop_id
 * @property string $tenant_id
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string|null $actor_name
 * @property string|null $buyer_email
 * @property string $action
 * @property string $operation_key
 * @property string $request_hash
 * @property string|null $previous_status
 * @property string $result_status
 * @property \Illuminate\Support\Carbon|null $hold_expires_at
 * @property string|null $decision_reason
 * @property array<string, mixed>|null $evidence
 * @property string $delivery_state
 * @property int $delivery_attempts
 * @property \Illuminate\Support\Carbon|null $delivery_claimed_at
 * @property \Illuminate\Support\Carbon|null $delivery_completed_at
 * @property string|null $delivery_error
 * @property \Illuminate\Support\Carbon|null $created_at
 */
final class VenueBookingAction extends Model
{
    /** @use HasFactory<VenueBookingActionFactory> */
    use HasFactory;

    use HasUuids;

    public const ACTOR_HOST = 'host';

    public const ACTOR_BUYER = 'buyer';

    public const ACTOR_SYSTEM = 'system';

    public const ACTION_REQUESTED = 'requested';

    public const ACTION_APPROVED = 'approved';

    public const ACTION_DECLINED = 'declined';

    public const ACTION_RELEASED = 'released';

    public const ACTION_RENEWED = 'renewed';

    public const ACTION_CANCELLED = 'cancelled';

    public const ACTION_PAYMENT_CALENDAR_CONFLICT = 'payment_calendar_conflict';

    public const DELIVERY_QUEUED = 'queued';

    public const DELIVERY_SENDING = 'sending';

    public const DELIVERY_SENT = 'sent';

    public const DELIVERY_FAILED = 'failed';

    public const DELIVERY_UNCERTAIN = 'uncertain';

    public const DELIVERY_NONE = 'none';

    /**
     * @var string
     */
    protected $connection = 'landlord';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'booking_id',
        'shop_id',
        'tenant_id',
        'actor_type',
        'actor_id',
        'actor_name',
        'buyer_email',
        'action',
        'operation_key',
        'request_hash',
        'previous_status',
        'result_status',
        'hold_expires_at',
        'decision_reason',
        'evidence',
        'delivery_state',
        'delivery_attempts',
        'delivery_claimed_at',
        'delivery_completed_at',
        'delivery_error',
    ];

    /**
     * @return BelongsTo<VenueBooking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(VenueBooking::class, 'booking_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actor_id' => 'integer',
            'hold_expires_at' => 'datetime',
            'evidence' => 'array',
            'delivery_attempts' => 'integer',
            'delivery_claimed_at' => 'datetime',
            'delivery_completed_at' => 'datetime',
        ];
    }
}
