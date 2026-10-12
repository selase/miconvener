<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EncryptedString;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An account the platform's commission is paid into. Not tied to an
 * organisation: every organiser on their own Paystack account points a
 * subaccount at one of these.
 *
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $accepted_until
 */
final class CommissionDestination extends Model
{
    /** @use HasFactory<\Database\Factories\CommissionDestinationFactory> */
    use HasFactory;

    use HasUuid;
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'label',
        'currency',
        'business_name',
        'settlement_bank',
        'account_number',
        'is_active',
        'accepted_until',
    ];

    /**
     * Accounts a subaccount may settle to: active, and not past their
     * retirement date.
     *
     * @param  Builder<CommissionDestination>  $query
     * @return Builder<CommissionDestination>
     */
    public function scopeAccepted(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('accepted_until')->orWhere('accepted_until', '>', now()));
    }

    /**
     * Accounts organisers are told to use: accepted and not being retired.
     *
     * @param  Builder<CommissionDestination>  $query
     * @return Builder<CommissionDestination>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('accepted_until');
    }

    public function isRetiring(): bool
    {
        return $this->is_active && $this->accepted_until !== null && $this->accepted_until->isFuture();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'accepted_until' => 'datetime',
            'account_number' => EncryptedString::class,
        ];
    }
}
