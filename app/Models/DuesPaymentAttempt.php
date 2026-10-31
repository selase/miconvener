<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class DuesPaymentAttempt extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $connection = 'landlord';

    protected $fillable = ['reference', 'tenant_id', 'member_id', 'charge_ids', 'currency', 'expected_amount', 'rate', 'cap_amount', 'bearer', 'commissions', 'terms_source', 'status', 'confirmed_at'];

    protected $casts = [
        'charge_ids' => 'array',
        'commissions' => 'array',
        'expected_amount' => 'integer',
        'rate' => 'decimal:2',
        'cap_amount' => 'integer',
        'confirmed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::updating(function (self $attempt): void {
            $mutable = ['status', 'confirmed_at'];
            if (array_diff(array_keys($attempt->getDirty()), $mutable) !== []) {
                throw new LogicException('A dues checkout financial snapshot cannot be changed.');
            }
        });
    }
}
