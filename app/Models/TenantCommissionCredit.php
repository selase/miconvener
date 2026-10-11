<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class TenantCommissionCredit extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\TenantCommissionCreditFactory> */
    use HasFactory;

    use HasUuid;
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'currency',
        'amount',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }
}
