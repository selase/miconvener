<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class CommunityPreferenceToken extends Model
{
    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $hidden = ['token_hash'];
}
