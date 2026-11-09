<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

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
