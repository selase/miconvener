<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $policy_id
 * @property int|null $actor_id
 * @property string $action
 * @property array<string,mixed> $evidence
 * @property \Carbon\CarbonImmutable $created_at
 */
final class PayoutPolicyAction extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
