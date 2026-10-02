<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $event_id
 * @property string $contributor_name
 * @property string|null $contributor_email
 * @property string|null $contributor_phone
 * @property int $amount
 * @property int $gateway_fee_amount
 * @property int $platform_fee_amount
 * @property int $net_amount
 * @property string $currency
 * @property string $status
 * @property string $payment_reference
 * @property string|null $paystack_reference
 * @property string $provider
 * @property string|null $tribute_message
 * @property bool $is_anonymous
 * @property bool $is_approved
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Tenant|null $tenant
 * @property-read Event|null $event
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EventLedgerEntry> $ledgerEntries
 */
final class EventContribution extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const string STATUS_PENDING_PAYMENT = 'pending_payment';

    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_REFUNDED = 'refunded';

    public const string STATUS_FAILED = 'failed';

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'contributor_name',
        'contributor_email',
        'contributor_phone',
        'amount',
        'gateway_fee_amount',
        'platform_fee_amount',
        'net_amount',
        'currency',
        'status',
        'payment_reference',
        'paystack_reference',
        'provider',
        'tribute_message',
        'is_anonymous',
        'is_approved',
        'paid_at',
    ];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<EventLedgerEntry, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(EventLedgerEntry::class, 'contribution_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function displayName(): string
    {
        if ($this->is_anonymous) {
            return 'Anonymous';
        }

        return mb_trim((string) $this->contributor_name) !== '' ? $this->contributor_name : 'Anonymous';
    }

    public function formattedAmount(): string
    {
        return sprintf('%s %.2f', $this->currency, $this->amount / 100);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'gateway_fee_amount' => 'integer',
            'platform_fee_amount' => 'integer',
            'net_amount' => 'integer',
            'is_anonymous' => 'boolean',
            'is_approved' => 'boolean',
            'paid_at' => 'datetime',
        ];
    }
}
