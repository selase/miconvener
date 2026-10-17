<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class CommunityInboxRead extends Model
{
    /** @use HasFactory<\Database\Factories\CommunityInboxReadFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $hidden = ['reader_key'];

    protected $casts = ['available_at' => 'immutable_datetime', 'withdrawn_at' => 'immutable_datetime', 'read_at' => 'immutable_datetime'];
}
