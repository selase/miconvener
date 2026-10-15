<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class CommunityGroup extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\CommunityGroupFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'name', 'description', 'archived_at'];

    protected $casts = ['archived_at' => 'datetime'];

    /** @return BelongsToMany<Member, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'community_group_members', 'group_id', 'member_id')->withPivot('tenant_id')->withTimestamps();
    }
}
