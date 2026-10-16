<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Temporary encrypted selection rows, not identities or member records. */
final class CommunityAudienceAddress extends Model
{
    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $hidden = ['address'];

    protected $casts = ['address' => 'encrypted', 'expires_at' => 'datetime'];
}
