<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $category
 * @property string $currency
 * @property int $version
 * @property string $status
 * @property string $payout_account_id
 * @property string $requester_security_fingerprint
 * @property string|null $approver_security_fingerprint
 * @property string $destination_display
 * @property string $terms_fingerprint
 * @property string $account_fingerprint
 * @property string $recipient_fingerprint
 * @property string $timezone
 * @property string $cadence
 * @property int|null $weekday
 * @property string $local_time
 * @property int $minimum_amount
 * @property int $maximum_amount
 * @property int $daily_limit
 * @property int $rolling_limit
 * @property int $reserve_amount
 * @property int $requested_by
 * @property int|null $approved_by
 * @property \Carbon\CarbonImmutable|null $approved_at
 * @property \Carbon\CarbonImmutable|null $active_after
 */
final class OrganisationPayoutPolicy extends Model
{
    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $hidden = ['account_fingerprint', 'recipient_fingerprint', 'terms_fingerprint', 'requester_security_fingerprint', 'approver_security_fingerprint'];

    protected function casts(): array
    {
        return ['requested_by' => 'integer', 'approved_by' => 'integer', 'version' => 'integer', 'weekday' => 'integer', 'minimum_amount' => 'integer', 'maximum_amount' => 'integer',
            'daily_limit' => 'integer', 'rolling_limit' => 'integer', 'reserve_amount' => 'integer',
            'approved_at' => 'immutable_datetime', 'active_after' => 'immutable_datetime'];
    }
}
