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
        'disk',
        'mime',
        'byte_size',
        'checksum',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_primary' => 'boolean',
        'sort_order' => 'integer',
        'byte_size' => 'integer',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'url',
    ];

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $data = parent::toArray();
        if ($this->merchandiseSlug() !== null) {
            unset($data['file_path'], $data['disk'], $data['checksum']);
        }

        return $data;
    }

    public function getUrlAttribute(): string
    {
        $slug = $this->merchandiseSlug();
        if ($slug !== null) {
            return route('marketplace.items.photos', ['slug' => $slug, 'media' => $this->id]);
        }

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

    protected static function booted(): void
    {
        self::addGlobalScope('merchandise', function (Builder $media): void {
            $media->where(function (Builder $query): void {
                $query->whereExists(fn ($legacy) => $legacy->selectRaw('1')->from('store_listings')
                    ->whereColumn('store_listings.id', 'store_listing_media.listing_id')->where('listing_kind', '!=', StoreListing::KIND_MERCHANDISE))
                    ->orWhereHas('listing');
            });
        });
    }

    private function merchandiseSlug(): ?string
    {
        return StoreListing::withoutGlobalScope(\App\Scopes\MerchandiseVisibilityScope::class)->withoutGlobalScope(\App\Scopes\StaffOnlyScope::class)
            ->whereKey($this->listing_id)->where('listing_kind', StoreListing::KIND_MERCHANDISE)->value('slug');
    }
}
