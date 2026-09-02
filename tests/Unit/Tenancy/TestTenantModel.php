<?php

declare(strict_types=1);

namespace Tests\Unit\Tenancy;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class TestTenantModel extends Model
{
    use BelongsToTenant;

    protected $table = 'test_table';

    protected $guarded = [];
}
