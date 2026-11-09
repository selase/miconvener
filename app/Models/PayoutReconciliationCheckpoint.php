<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $account_fingerprint
 * @property string $scope_key
 * @property \Carbon\CarbonImmutable $observed_at
 * @property string $status
 * @property array<string,mixed> $evidence
 * @property int|null $reviewed_by
 * @property \Carbon\CarbonImmutable $created_at
 */
final class PayoutReconciliationCheckpoint extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'observed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
