<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $policy_id
 * @property string|null $event_id
 * @property int $revision
 * @property string $scope_key
 * @property string $window_key
 * @property string $status
 * @property string|null $reason
 * @property array{eligible:bool,reason:?string,entitlement:int,reserve:int,available:int,currency:string,fee:int,amount:int,net:int,terms_fingerprint:string} $snapshot
 * @property int $amount
 * @property string|null $payout_id
 * @property string|null $operation_key
 * @property int|null $approved_by
 * @property \Carbon\CarbonImmutable $expires_at
 * @property \Carbon\CarbonImmutable|null $submit_before
 */
final class ScheduledPayoutProposal extends Model
{
    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'amount' => 'integer', 'expires_at' => 'immutable_datetime', 'submit_before' => 'immutable_datetime'];
    }
}
