<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BadgeLibraryVersion extends Model
{
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['design_id', 'version', 'snapshot'];

    /** @return BelongsTo<BadgeLibraryDesign, $this> */
    public function design(): BelongsTo
    {
        return $this->belongsTo(BadgeLibraryDesign::class, 'design_id');
    }

    /** @return array<string, mixed> */
    public function snapshotSettings(): array
    {
        $snapshot = $this->getAttribute('snapshot');

        return is_array($snapshot) ? $snapshot : [];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'version' => 'integer'];
    }
}
