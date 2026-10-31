<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class CommunityJoinRequest extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\CommunityJoinRequestFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $hidden = ['address', 'address_hash', 'capability_hash'];

    protected $casts = ['address' => 'encrypted', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'credential_issued_at' => 'datetime', 'member_access_version' => 'integer'];
}
