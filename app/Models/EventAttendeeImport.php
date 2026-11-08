<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class EventAttendeeImport extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $casts = ['expires_at' => 'immutable_datetime', 'confirmed_at' => 'immutable_datetime'];

    /** @return HasMany<EventAttendeeImportRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(EventAttendeeImportRow::class, 'import_id')->orderBy('line');
    }
}
