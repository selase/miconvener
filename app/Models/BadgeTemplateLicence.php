<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $buyer_tenant_id
 * @property string $event_id
 * @property string $family_id
 * @property string $state
 * @property string|null $current_purchase_id
 * @property \Carbon\CarbonImmutable|null $first_used_at
 */
final class BadgeTemplateLicence extends Model
{
    /** @use HasFactory<\Database\Factories\BadgeTemplateLicenceFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['buyer_tenant_id', 'event_id', 'family_id', 'state', 'current_purchase_id', 'first_used_at'];

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    /** @return BelongsTo<BadgeLibraryDesign, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(BadgeLibraryDesign::class, 'family_id');
    }

    /** @return BelongsTo<BadgeTemplatePurchase, $this> */
    public function currentPurchase(): BelongsTo
    {
        return $this->belongsTo(BadgeTemplatePurchase::class, 'current_purchase_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['first_used_at' => 'immutable_datetime'];
    }
}
