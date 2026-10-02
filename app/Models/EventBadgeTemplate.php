<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EventBadgeTemplate extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id', 'event_id', 'width_mm', 'height_mm', 'orientation',
        'background_disk', 'background_path', 'layout', 'tier_styles',
        'sheet_settings', 'design_version',
    ];

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'width_mm' => 'float',
            'height_mm' => 'float',
            'layout' => 'array',
            'tier_styles' => 'array',
            'sheet_settings' => 'array',
            'design_version' => 'integer',
        ];
    }
}
