<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $account_fingerprint
 * @property int $remaining
 * @property int $observed_balance
 * @property \Carbon\CarbonImmutable $observed_at
 * @property bool $blocked
 */
final class ProviderPayoutLiquidity extends Model
{
    public $incrementing = false;

    protected $connection = 'landlord';

    protected $table = 'provider_payout_liquidity';

    protected $primaryKey = 'account_fingerprint';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['remaining' => 'integer', 'observed_balance' => 'integer', 'observed_at' => 'immutable_datetime', 'blocked' => 'boolean'];
    }
}
