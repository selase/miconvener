<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class EventPayout extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const string STATUS_SCHEDULED = 'scheduled';

    public const string STATUS_PAID = 'paid';

    public const string STATUS_PROCESSING = 'processing';

    public const string STATUS_FAILED = 'failed';

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'payout_account_id',
        'amount',
        'status',
        'scheduled_at',
        'paid_at',
        'note',
        'provider_reference',
        'failure_reason',
    ];

    protected $casts = [
        'amount' => 'integer',
        'scheduled_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function payoutAccount(): BelongsTo
    {
        return $this->belongsTo(TenantPayoutAccount::class, 'payout_account_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(EventLedgerEntry::class, 'payout_id');
    }
}
