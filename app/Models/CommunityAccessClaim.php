<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class CommunityAccessClaim extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\CommunityAccessClaimFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $hidden = ['phone', 'phone_hash', 'capability_hash'];

    protected $casts = ['phone' => 'encrypted', 'expires_at' => 'datetime', 'completed_at' => 'datetime', 'member_access_version' => 'integer'];
}
