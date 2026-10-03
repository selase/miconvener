<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Shop extends Model
{
    use HasFactory;
    use HasUuids;

    public const string VERIFICATION_UNVERIFIED = 'unverified';

    public const string VERIFICATION_PENDING = 'pending';

    public const string VERIFICATION_VERIFIED = 'verified';

    public const string VERIFICATION_REJECTED = 'rejected';

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'description',
        'logo_path',
        'cover_image_path',
        'email',
        'phone',
        'address',
        'city',
        'region',
        'vendor_categories',
        'business_registration_number',
        'tax_id',
        'verification_documents',
        'past_clients',
        'average_rating',
        'reviews_count',
        'latitude',
        'longitude',
        'verification_status',
        'verified_at',
        'verified_by_user_id',
        'rejection_reason',
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'vendor_categories' => 'array',
        'verification_documents' => 'array',
        'past_clients' => 'array',
        'average_rating' => 'decimal:2',
        'reviews_count' => 'integer',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'verified_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'verified_by_user_id',
        'rejection_reason',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return HasMany<StoreListing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(StoreListing::class);
    }

    /**
     * @return HasMany<VenueBooking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(VenueBooking::class);
    }

    /**
     * @return HasMany<VenueFacilityMessage, $this>
     */
    public function facilityMessages(): HasMany
    {
        return $this->hasMany(VenueFacilityMessage::class);
    }

    /**
     * @return HasMany<VenueInspectionLog, $this>
     */
    public function inspectionLogs(): HasMany
    {
        return $this->hasMany(VenueInspectionLog::class);
    }

    /**
     * @return HasMany<MarketplaceQuote, $this>
     */
    public function quotes(): HasMany
    {
        return $this->hasMany(MarketplaceQuote::class);
    }

    /**
     * @return HasMany<MarketplaceReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(MarketplaceReview::class)->where('is_published', true);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function recalculateRating(): void
    {
        $stats = $this->reviews()
            ->selectRaw('COUNT(*) as total_count, AVG(rating) as avg_rating')
            ->first();

        $this->update([
            'reviews_count' => (int) ($stats->total_count ?? 0),
            'average_rating' => round((float) ($stats->avg_rating ?? 0.0), 2),
        ]);
    }

    public function hasVendorCategory(string $category): bool
    {
        return in_array($category, $this->vendor_categories ?? [], true);
    }

    public function isVerified(): bool
    {
        return $this->verification_status === self::VERIFICATION_VERIFIED;
    }

    public function isPendingVerification(): bool
    {
        return $this->verification_status === self::VERIFICATION_PENDING;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('verification_status', self::VERIFICATION_VERIFIED);
    }
}
