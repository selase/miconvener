<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StoreListingMedia extends Model
{
    use HasUuids;

    public const string TYPE_PHOTO = 'photo';

    public const string TYPE_FLOOR_PLAN_PDF = 'floor_plan_pdf';

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_APPROVED = 'approved';

    public const string STATUS_REJECTED = 'rejected';

    protected $connection = 'landlord';

    protected $fillable = [
        'listing_id',
        'media_type',
        'file_path',
        'title',
        'sort_order',
        'is_primary',
        'status',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_primary' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'url',
    ];

    public function getUrlAttribute(): string
    {
        if (str_starts_with($this->file_path, 'http://') || str_starts_with($this->file_path, 'https://')) {
            return $this->file_path;
        }

        return \Illuminate\Support\Facades\Storage::disk('public')->url($this->file_path);
    }

    /**
     * @return BelongsTo<StoreListing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(StoreListing::class, 'listing_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePhotos(Builder $query): Builder
    {
        return $query->where('media_type', self::TYPE_PHOTO);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFloorPlans(Builder $query): Builder
    {
        return $query->where('media_type', self::TYPE_FLOOR_PLAN_PDF);
    }
}
