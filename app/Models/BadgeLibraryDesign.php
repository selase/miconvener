<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BadgeLibraryDesign extends Model
{
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'event_id', 'scope', 'name', 'price_pesewas', 'withdrawn_at'];

    /** @return HasMany<BadgeLibraryVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(BadgeLibraryVersion::class, 'design_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['price_pesewas' => 'integer', 'withdrawn_at' => 'datetime'];
    }
}
