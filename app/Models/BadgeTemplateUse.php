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
 * @property string $purchase_id
 * @property int|null $actor_id
 * @property string $version_id
 * @property string $purpose
 * @property string $output_key
 * @property array<string, mixed> $render_snapshot
 * @property string $snapshot_hash
 */
final class BadgeTemplateUse extends Model
{
    /** @use HasFactory<\Database\Factories\BadgeTemplateUseFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['licence_id', 'purchase_id', 'actor_id', 'version_id', 'purpose', 'output_key', 'snapshot_hash', 'render_snapshot'];

    /** @return BelongsTo<BadgeTemplateLicence, $this> */
    public function licence(): BelongsTo
    {
        return $this->belongsTo(BadgeTemplateLicence::class, 'licence_id');
    }

    /** @return BelongsTo<BadgeTemplatePurchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(BadgeTemplatePurchase::class, 'purchase_id');
    }

    /** @return BelongsTo<BadgeLibraryVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(BadgeLibraryVersion::class, 'version_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['actor_id' => 'integer', 'render_snapshot' => 'array'];
    }
}
