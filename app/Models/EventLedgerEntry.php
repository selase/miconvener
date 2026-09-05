<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EventLedgerEntry extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const string TYPE_CHARGE = 'charge';

    public const string TYPE_REFUND = 'refund';

    public const string TYPE_PAYOUT = 'payout';

    public $timestamps = false;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'type',
        'registration_id',
        'payout_id',
        'gross_amount',
        'gateway_fee_amount',
        'commission_amount',
        'net_amount',
        'currency',
        'provider',
        'provider_reference',
    ];

    protected $casts = [
        'gross_amount' => 'integer',
        'gateway_fee_amount' => 'integer',
        'commission_amount' => 'integer',
        'net_amount' => 'integer',
        'created_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'registration_id');
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(EventPayout::class, 'payout_id');
    }
}
