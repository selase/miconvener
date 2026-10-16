<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class CommunityMessagePreference extends Model
{
    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $hidden = ['address', 'token_hash'];

    protected $casts = ['address' => 'encrypted', 'marketing_disabled_at' => 'datetime'];

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<CommunityPreferenceToken, $this> */
    public function tokens(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CommunityPreferenceToken::class, 'preference_id');
    }
}
