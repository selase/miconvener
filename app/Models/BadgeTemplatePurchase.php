<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $licence_id
 * @property string $order_id
 * @property string $buyer_tenant_id
 * @property string $event_id
 * @property string $family_id
 * @property string $version_id
 * @property array<string, mixed> $terms_snapshot
 * @property bool $uses_exists
 * @property string $state
 */
final class BadgeTemplatePurchase extends Model
{
    /** @use HasFactory<\Database\Factories\BadgeTemplatePurchaseFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['licence_id', 'order_id', 'buyer_tenant_id', 'event_id', 'family_id', 'version_id', 'terms_snapshot', 'state'];

    /** @return BelongsTo<BadgeTemplateLicence, $this> */
    public function licence(): BelongsTo
    {
        return $this->belongsTo(BadgeTemplateLicence::class, 'licence_id');
    }

    /** @return BelongsTo<StoreOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class, 'order_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<BadgeTemplateUse, $this> */
    public function uses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BadgeTemplateUse::class, 'purchase_id');
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    /** @return BelongsTo<BadgeLibraryVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(BadgeLibraryVersion::class, 'version_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['terms_snapshot' => 'array'];
    }
}
