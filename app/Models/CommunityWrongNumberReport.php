<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class CommunityWrongNumberReport extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\CommunityWrongNumberReportFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $hidden = ['phone', 'phone_hash'];

    protected $casts = ['phone' => 'encrypted', 'reviewed_at' => 'datetime'];
}
