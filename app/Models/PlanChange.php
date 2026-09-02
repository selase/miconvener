<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PlanChange extends Model
{
    use HasUuid;

    protected $connection = 'landlord';

    protected $guarded = [];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function fromPackage(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'from_package_id');
    }

    public function toPackage(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'to_package_id');
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime',
            'processed_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
