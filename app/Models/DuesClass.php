<?php

declare(strict_types=1);

namespace App\Models;

use App\Enum\DuesPeriodEnum;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $amount Minor currency units.
 * @property DuesPeriodEnum $period
 */
final class DuesClass extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\DuesClassFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'name',
        'amount',
        'currency',
        'period',
        'is_active',
    ];

    protected $casts = [
        'amount' => 'integer',
        'period' => DuesPeriodEnum::class,
        'is_active' => 'boolean',
    ];

    /**
     * @return HasMany<Member, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }
}
