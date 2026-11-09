<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $payout_id
 * @property string $account_fingerprint
 * @property string $provider_reference
 * @property int $amount
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable|null $reconciled_at
 */
final class PayoutLiquidityAllocation extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'created_at' => 'immutable_datetime', 'reconciled_at' => 'immutable_datetime'];
    }
}
