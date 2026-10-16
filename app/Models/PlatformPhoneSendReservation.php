<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

final class PlatformPhoneSendReservation extends Model
{
    use HasUuids;
    use MassPrunable;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $casts = ['day' => 'date', 'submitted_at' => 'datetime'];

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return self::query()->whereIn('status', ['submitted', 'failed'])->where('created_at', '<', now()->subDays(30));
    }
}
