<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class WebhookEndpoint extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected $casts = [
        'events' => 'array',
        'secret' => 'encrypted',
        'is_active' => 'boolean',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function calls()
    {
        return $this->hasMany(WebhookCall::class);
    }
}
