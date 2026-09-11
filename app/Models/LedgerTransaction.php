<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LedgerTransaction extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const TYPE_TICKET_SALE = 'ticket_sale';

    public const TYPE_REFUND = 'refund';

    public const TYPE_HOLDBACK_RETENTION = 'holdback_retention';

    public const TYPE_HOLDBACK_RELEASE = 'holdback_release';

    public const TYPE_PAYOUT = 'payout';

    public const TYPE_ADJUSTMENT = 'adjustment';

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'reference',
        'description',
        'transaction_type',
        'posted_at',
        'created_by',
    ];

    protected $casts = [
        'posted_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'transaction_id');
    }

    public function totalDebits(): int
    {
        return (int) $this->entries()->where('direction', LedgerEntry::DIRECTION_DEBIT)->sum('amount');
    }

    public function totalCredits(): int
    {
        return (int) $this->entries()->where('direction', LedgerEntry::DIRECTION_CREDIT)->sum('amount');
    }

    public function isBalanced(): bool
    {
        return $this->totalDebits() === $this->totalCredits();
    }
}
