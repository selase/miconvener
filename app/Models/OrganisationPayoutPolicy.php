<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class OrganisationPayoutPolicy extends Model
{
    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $hidden = ['account_fingerprint', 'recipient_fingerprint'];

    protected function casts(): array
    {
        return ['requested_by' => 'integer', 'approved_by' => 'integer', 'version' => 'integer', 'weekday' => 'integer', 'minimum_amount' => 'integer', 'maximum_amount' => 'integer',
            'daily_limit' => 'integer', 'rolling_limit' => 'integer', 'reserve_amount' => 'integer',
            'approved_at' => 'immutable_datetime', 'active_after' => 'immutable_datetime'];
    }
}
