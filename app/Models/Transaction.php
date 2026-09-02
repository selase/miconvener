<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class Transaction extends Model
{
    use HasFactory, HasUuids;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'amount' => 'integer',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }
}
