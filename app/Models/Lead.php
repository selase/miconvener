<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An Enterprise enquiry and how its follow-up is going.
 */
final class Lead extends Model
{
    use HasFactory;

    /** @var list<string> */
    public const array STATUSES = ['new', 'contacted', 'won', 'lost'];

    protected $connection = 'landlord';

    protected $fillable = [
        'name',
        'email',
        'company',
        'message',
        'status',
        'ip_address',
        'meta',
        'notes',
        'status_changed_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'status_changed_at' => 'datetime',
    ];
}
