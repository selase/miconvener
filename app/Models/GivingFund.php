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
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property string $button_label
 * @property string $currency
 * @property string $kind
 * @property int|null $target
 * @property \Carbon\CarbonImmutable|null $ends_on
 * @property bool $close_on_target
 * @property string $state
 * @property string $visibility
 * @property string $progress
 * @property int $minimum_amount
 * @property string|null $event_id
 * @property int|null $created_by
 * @property array<int, array<string, mixed>> $audit
 */
final class GivingFund extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\GivingFundFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'slug', 'name', 'description', 'button_label', 'currency', 'kind', 'target', 'ends_on', 'close_on_target', 'state', 'visibility', 'progress', 'minimum_amount', 'event_id', 'created_by', 'audit'];

    protected $hidden = [];

    protected $casts = ['target' => 'integer', 'minimum_amount' => 'integer', 'ends_on' => 'immutable_date', 'close_on_target' => 'boolean', 'audit' => 'array'];

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
