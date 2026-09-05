<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EventMaterialDownload extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'material_id',
        'registration_id',
        'ip_address',
        'user_agent',
    ];

    public function material(): BelongsTo
    {
        return $this->belongsTo(EventMaterial::class, 'material_id');
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class);
    }
}
