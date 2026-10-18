<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use DomainException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CommunityLibraryVersion extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\CommunityLibraryVersionFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'item_id', 'edition', 'disk', 'path', 'mime', 'size', 'checksum', 'created_by'];

    protected $hidden = ['disk', 'path', 'checksum', 'created_by'];

    protected $casts = ['size' => 'integer'];

    /** @return BelongsTo<CommunityLibraryItem,$this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(CommunityLibraryItem::class, 'item_id');
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new DomainException('Published file versions are immutable. Upload a new edition.'));
    }
}
