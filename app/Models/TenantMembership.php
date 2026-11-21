<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

final class TenantMembership extends Pivot
{
    public $incrementing = false;

    protected $connection = 'landlord';

    protected $table = 'tenant_user';

    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setPivotKeys('tenant_id', 'user_id');
    }

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime', 'suspended_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
