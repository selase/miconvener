<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class BadgeDesignRevision extends Model
{
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'event_id', 'design_version', 'snapshot'];

    /** @return array<string, mixed> */
    public function snapshotSettings(): array
    {
        $snapshot = $this->getAttribute('snapshot');

        return is_array($snapshot) ? $snapshot : [];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'design_version' => 'integer'];
    }
}
