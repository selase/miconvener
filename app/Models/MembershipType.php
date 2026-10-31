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
 * Columns come from a renamed table (dues_classes → membership_types), which
 * static analysis cannot follow through the migrations, so they are listed here.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $currency
 * @property bool $is_active
 * @property int|null $amount Minor currency units; null when the type carries no dues.
 * @property \Carbon\CarbonImmutable|null $dues_start_on Earliest eligible full billing period; null preserves legacy schedules.
 * @property DuesPeriodEnum|null $period Null exactly when the amount is null.
 * @property-read bool|null $charges_exists Set by withExists('charges') or loadExists('charges').
 */
final class MembershipType extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\MembershipTypeFactory> */
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
        'dues_start_on',
    ];

    protected $casts = [
        'amount' => 'integer',
        'dues_start_on' => 'immutable_date',
        'period' => DuesPeriodEnum::class,
        'is_active' => 'boolean',
    ];

    /**
     * Whether members of this type owe dues. A type without an amount
     * (honorary members, a church register) never raises charges.
     */
    public function hasDues(): bool
    {
        return $this->amount !== null;
    }

    /**
     * @return HasMany<Member, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    /**
     * @return HasMany<DuesCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(DuesCharge::class);
    }

    protected static function booted(): void
    {
        self::updating(function (MembershipType $type): void {
            if ($type->isDirty('amount') && $type->getOriginal('amount') === null && $type->hasDues()) {
                $tenant = Tenant::query()->findOrFail($type->tenant_id);
                $today = \Carbon\CarbonImmutable::now(CommunitySettings::for($tenant)->timezone)->startOfDay();
                if ($type->dues_start_on === null || (! $type->isDirty('dues_start_on') && $type->dues_start_on->lt($today))) {
                    $type->dues_start_on = $today;
                }
            }
        });
    }
}
