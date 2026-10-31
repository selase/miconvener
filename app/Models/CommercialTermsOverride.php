<?php

declare(strict_types=1);

namespace App\Models;

use App\Enum\CommercialStreamEnum;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class CommercialTermsOverride extends Model
{
    use BelongsToTenant;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'stream', 'rate', 'cap_amount', 'reason', 'set_by'];

    protected $casts = [
        'stream' => CommercialStreamEnum::class,
        'rate' => 'decimal:2',
        'cap_amount' => 'integer',
        'set_by' => 'integer',
    ];
}
