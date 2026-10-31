<?php

declare(strict_types=1);

namespace App\Models;

use App\Enum\CommercialStreamEnum;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class CommercialTermsChange extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'stream', 'old_rate', 'new_rate', 'old_cap', 'new_cap', 'reason', 'changed_by'];

    protected $casts = [
        'stream' => CommercialStreamEnum::class,
        'old_rate' => 'decimal:2',
        'new_rate' => 'decimal:2',
        'old_cap' => 'integer',
        'new_cap' => 'integer',
        'changed_by' => 'integer',
    ];
}
