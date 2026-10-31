<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string $tenant_id
 * @property string $fund_id
 * @property string|null $event_contribution_id
 * @property string $origin
 * @property string $category
 * @property \Carbon\CarbonImmutable $associated_at
 */
final class GivingContributionLink extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\GivingContributionLinkFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'fund_id', 'event_contribution_id', 'origin', 'category', 'associated_at'];

    protected $hidden = [];

    protected $casts = ['associated_at' => 'immutable_datetime'];

    /** @return BelongsTo<GivingFund, $this> */
    public function fund(): BelongsTo
    {
        return $this->belongsTo(GivingFund::class);
    }
}
