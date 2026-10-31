<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class CommunityAccessCredential extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\CommunityAccessCredentialFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $hidden = ['phone', 'phone_hash', 'secret_hash'];

    protected $casts = ['phone' => 'encrypted', 'version' => 'integer', 'member_access_version' => 'integer', 'approved_at' => 'datetime', 'revoked_at' => 'datetime', 'suspended_at' => 'datetime'];
}
