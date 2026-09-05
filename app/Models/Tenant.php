<?php

declare(strict_types=1);

namespace App\Models;

use App\Enum\TenantStatusEnum;
use App\Libraries\Helper;
use App\Traits\HasUuid;
use App\Traits\SpatieActivityLogs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Permission\Traits\HasRoles;

final class Tenant extends Model
{
    use HasFactory;
    use HasRoles;
    use HasUuid;
    use HasUuids;
    use SpatieActivityLogs;

    protected $connection = 'landlord';

    protected $fillable = [
        'id',
        'uuid',
        'name',
        'slug',
        'email',
        'phone_number',
        'address',
        'country',
        'city',
        'state',
        'zipcode',
        'logo',
        'status',
        'isolation_mode',
        'db_driver',
        'db_secret_ref',
        's3_mode',
        's3_secret_ref',
        'encryption_at_rest',
        'kms_key_ref',
        'meta',
        'package_id',
        'onboarding_completed_at',
        'llm_models_whitelist',
        'allowed_ips',
        'custom_llm_limit',
        'require_2fa',
        'custom_domain',
        'custom_domain_verified_at',
        'custom_domain_status',
        'markup_percentage',
        'llm_topup_balance',
        'platform_fee_percentage',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'status' => TenantStatusEnum::class,
        'encryption_at_rest' => 'boolean',
        'meta' => 'array',
        'onboarding_completed_at' => 'datetime',
        'llm_models_whitelist' => 'array',
        'allowed_ips' => 'array',
        'require_2fa' => 'boolean',
        'markup_percentage' => 'decimal:2',
        'llm_topup_balance' => 'integer',
        'platform_fee_percentage' => 'float',
    ];

    public function requiresDedicatedDb(): bool
    {
        return in_array($this->isolation_mode, ['db_per_tenant', 'byo']);
    }

    public function encryptionAtRestEnabled(): bool
    {
        return (bool) $this->encryption_at_rest;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', TenantStatusEnum::ACTIVE);
    }

    public function scopeDeactivated(Builder $query): Builder
    {
        return $query->where('status', TenantStatusEnum::DEACTIVATED);
    }

    public function scopeWithIsolationMode(Builder $query, string $mode): Builder
    {
        return $query->where('isolation_mode', $mode);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function features(): HasMany
    {
        return $this->hasMany(TenantFeature::class);
    }

    public function featureEnabled(string $key): bool
    {
        return $this->features()->where('feature_key', $key)->where('enabled', true)->exists();
    }

    public function paymentGateways(): HasMany
    {
        return $this->hasMany(TenantPaymentGateway::class);
    }

    public function payoutAccounts(): HasMany
    {
        return $this->hasMany(TenantPayoutAccount::class)->orderByDesc('created_at');
    }

    public function hasActivePaymentGateway(): bool
    {
        return $this->paymentGateways()->where('is_active', true)->exists();
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function latestSubscription()
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    /**
     * Sync features from the assigned package to tenant_features table.
     */
    public function syncFeaturesFromPackage(): void
    {
        if (! $this->package_id) {
            return;
        }

        $package = $this->package()->with('features')->first();
        if (! $package) {
            return;
        }

        $packageFeatureSlugs = $package->features->pluck('slug')->toArray();

        // 1. Disable features that were package-sourced but are not in the current package
        $this->features()
            ->where('enabled', true)
            ->whereNotNull('meta')
            ->whereNotIn('feature_key', $packageFeatureSlugs)
            ->get()
            ->each(function ($feature): void {
                if (isset($feature->meta['source']) && $feature->meta['source'] === 'package') {
                    $feature->update(['enabled' => false]);
                    \Illuminate\Support\Facades\Cache::forget("tenant_{$this->id}_feature_{$feature->feature_key}");
                }
            });

        // 2. Enable/Update features from the new package
        foreach ($package->features as $feature) {
            $this->features()->updateOrCreate(
                ['feature_key' => $feature->slug],
                [
                    'enabled' => true,
                    'meta' => [
                        'value' => $feature->pivot->value,
                        'type' => $feature->type,
                        'source' => 'package',
                        'package_id' => $this->package_id,
                    ],
                ]
            );
            \Illuminate\Support\Facades\Cache::forget("tenant_{$this->id}_feature_{$feature->slug}");
        }

        // Flush entitlement permission cache so role checks reflect new features immediately
        \Illuminate\Support\Facades\Cache::forget("tenant_{$this->id}_allowed_permissions");
    }

    /**
     * Get the current billable count based on the package billing model.
     */
    public function getBillableCountAttribute(): int
    {
        if (! $this->package_id || ! $this->package) {
            return 1;
        }

        if ($this->package->billing_model === Package::BILLING_MODEL_PER_SEAT) {
            return $this->users()->count();
        }

        return 1;
    }

    /**
     * Get the tenant's full URL.
     */
    public function url(string $path = ''): string
    {
        if ($this->custom_domain && $this->custom_domain_status === 'active') {
            return 'https://'.mb_rtrim($this->custom_domain, '/').($path !== '' && $path !== '0' ? '/'.mb_ltrim($path, '/') : '');
        }

        $baseUrl = config('app.url');
        $domain = str_replace('://', "://{$this->slug}.", $baseUrl);

        return mb_rtrim($domain, '/').($path !== '' && $path !== '0' ? '/'.mb_ltrim($path, '/') : '');
    }

    /* -----------------------------------------------------------------
     |  Metered Usage / Feature Limits
     | -----------------------------------------------------------------
     */

    public function usage(): HasMany
    {
        return $this->hasMany(TenantFeatureUsage::class);
    }

    public function usagePrices(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(UsagePrice::class, 'target');
    }

    /**
     * Record usage for a specific feature.
     */
    public function recordUsage(string $featureSlug, int $quantity = 1): void
    {
        // Simple increment for "lifetime" usage or current period usage
        // A more robust system would handle period dates here.
        /** @var TenantFeatureUsage $usage */
        $usage = $this->usage()->firstOrCreate(
            [
                'feature_slug' => $featureSlug,
                'period_start' => null, // null for lifetime or handle dates
                'period_end' => null,
            ],
            ['used_count' => 0]
        );

        $usage->increment('used_count', $quantity);
    }

    /**
     * Check if the tenant can use a feature based on its limit.
     */
    public function canUse(string $featureSlug, int $quantity = 1): bool
    {
        // 1. Is feature enabled?
        $feature = $this->features()->where('feature_key', $featureSlug)->first();

        if (! $feature) {
            // dump("Feature not found: $featureSlug for Tenant {$this->id}");
            return false;
        }
        if (! $feature->enabled) {
            // dump("Feature disabled: $featureSlug");
            return false;
        }

        // 2. Is it a metered feature with a limit?
        $meta = $feature->meta ?? [];
        if (! isset($meta['type']) || $meta['type'] !== 'limit') {
            // Boolean features are already checked by 'enabled'
            return true;
        }

        // 3. Check limit vs usage
        $limit = (int) ($meta['value'] ?? 0);
        if ($limit < 0) {
            return true;
        }

        $usage = $this->usage()
            ->where('feature_slug', $featureSlug)
            ->whereNull('period_start') // Assuming lifetime limit for now
            ->value('used_count') ?? 0;

        // dump("Usage: $usage, Quantity: $quantity, Limit: $limit");

        return ($usage + $quantity) <= $limit;
    }

    /**
     * Get the configured numeric limit for a limit-type feature, or null
     * when the feature is missing, disabled, not a limit type, or set to
     * unlimited (a negative stored value). Unlike canUse()/recordUsage(),
     * this does not consult TenantFeatureUsage — it's for gates that
     * compare against a live COUNT query instead of a running counter
     * (e.g. concurrent events-in-flight, current team seat count).
     */
    public function featureLimitValue(string $featureSlug): ?int
    {
        $feature = $this->features()->where('feature_key', $featureSlug)->first();

        if (! $feature || ! $feature->enabled) {
            return null;
        }

        $meta = $feature->meta ?? [];
        if (($meta['type'] ?? null) !== 'limit') {
            return null;
        }

        $limit = (int) ($meta['value'] ?? 0);

        return $limit < 0 ? null : $limit;
    }

    /**
     * Get the user's avatar.
     */
    protected function gravatar(): Attribute
    {
        return Attribute::make(
            get: fn (): string => Helper::generateUiAvatar($this->name),
        );
    }

    /**
     * Get the tenant's primary color or default.
     */
    protected function primaryColor(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->meta['primary_color'] ?? '#009EF7',
        );
    }

    /**
     * Get the tenant's logo URL or default to gravatar.
     */
    protected function logoUrl(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->logo) {
                    $disk = config('app.env') === 'production' ? 's3' : 'public';

                    return \Illuminate\Support\Facades\Storage::disk($disk)->url($this->logo);
                }

                return null;
            }
        );
    }

    /**
     * Get the tenant's custom email sender name or fall back to tenant name.
     */
    protected function emailSenderName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->attributes['email_sender_name'] ?? $this->name,
        );
    }

    /**
     * Get the tenant's custom email sender address (nullable).
     */
    protected function emailSenderAddress(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->attributes['email_sender_address'] ?? null,
        );
    }
}
