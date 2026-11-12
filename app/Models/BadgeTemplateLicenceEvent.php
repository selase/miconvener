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
 * @property string $action
 * @property string $operation_key
 * @property array<string, mixed> $evidence
 */
final class BadgeTemplateLicenceEvent extends Model
{
    /** @use HasFactory<\Database\Factories\BadgeTemplateLicenceEventFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['licence_id', 'purchase_id', 'actor_id', 'action', 'operation_key', 'evidence'];

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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['evidence' => 'array'];
    }
}
