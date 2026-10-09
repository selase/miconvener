<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ArtifactFont extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'family', 'name', 'disk', 'faces', 'archived_at'];

    /** @return array<int, array{path: string, hash: string}> */
    public function facesSettings(): array
    {
        $faces = $this->getAttribute('faces');

        return is_array($faces) ? $faces : [];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['faces' => 'array', 'archived_at' => 'datetime'];
    }
}
