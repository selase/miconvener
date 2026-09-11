<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LedgerAccount extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const TYPE_ASSET = 'asset';

    public const TYPE_LIABILITY = 'liability';

    public const TYPE_EQUITY = 'equity';

    public const TYPE_REVENUE = 'revenue';

    public const TYPE_EXPENSE = 'expense';

    // Standard Event Account Codes
    public const CODE_GATEWAY_CLEARING = '1000'; // Asset

    public const CODE_BANK = '1010';             // Asset

    public const CODE_ORGANIZER_PAYABLE = '2000'; // Liability

    public const CODE_HOLDBACK_RESERVE = '2010'; // Liability

    public const CODE_PLATFORM_REVENUE = '4000'; // Revenue

    public const CODE_GATEWAY_FEES = '5000';     // Expense

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'code',
        'name',
        'type',
        'currency',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'account_id');
    }

    /**
     * Compute current balance based on standard accounting rules:
     * Asset / Expense: Debits increase (+), Credits decrease (-)
     * Liability / Revenue / Equity: Credits increase (+), Debits decrease (-)
     */
    public function currentBalance(): int
    {
        $debits = (int) $this->entries()->where('direction', LedgerEntry::DIRECTION_DEBIT)->sum('amount');
        $credits = (int) $this->entries()->where('direction', LedgerEntry::DIRECTION_CREDIT)->sum('amount');

        if (in_array($this->type, [self::TYPE_ASSET, self::TYPE_EXPENSE], true)) {
            return $debits - $credits;
        }

        return $credits - $debits;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
