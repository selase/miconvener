<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

final class PlatformPhoneAccessCode extends Model
{
    /** @use HasFactory<\Database\Factories\PlatformPhoneAccessCodeFactory> */
    use HasFactory;

    use HasUuids;
    use MassPrunable;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $hidden = ['phone', 'phone_hash', 'ip_hash', 'code_hash'];

    protected $casts = ['phone' => 'encrypted', 'attempts' => 'integer', 'expires_at' => 'datetime', 'consumed_at' => 'datetime', 'submitted_at' => 'datetime'];

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return self::query()->where('expires_at', '<', now()->subDays(2));
    }
}
