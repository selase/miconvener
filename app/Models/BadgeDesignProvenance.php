<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $source_type
 * @property string $source_id
 * @property string $family_id
 */
final class BadgeDesignProvenance extends Model
{
    /** @use HasFactory<\Database\Factories\BadgeDesignProvenanceFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['source_type', 'source_id', 'family_id'];

    /** @return BelongsTo<BadgeLibraryDesign, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(BadgeLibraryDesign::class, 'family_id');
    }
}
