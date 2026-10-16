<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class CommunityFollower extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\CommunityFollowerFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = [];

    protected $hidden = ['address_hash'];

    protected $casts = ['address' => 'encrypted', 'consented_at' => 'datetime', 'unfollowed_at' => 'datetime'];
}
