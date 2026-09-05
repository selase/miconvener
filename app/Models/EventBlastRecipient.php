<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EventBlastRecipient extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'blast_id',
        'registration_id',
        'opened_at',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
    ];

    public function blast(): BelongsTo
    {
        return $this->belongsTo(EventBlast::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'registration_id');
    }
}
