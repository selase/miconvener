<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class CommunityAccessAudit extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\CommunityAccessAuditFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $hidden = [];

    protected $casts = [];
}
