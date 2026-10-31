<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class CommunityLibraryWrite extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\CommunityLibraryWriteFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'actor_id', 'write_key', 'fingerprint', 'item_id'];

    protected $hidden = ['fingerprint', 'write_key', 'actor_id'];
}
