<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EncryptedString;
use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class TenantPaymentGateway extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuid;
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'provider',
        'api_key_encrypted',
        'public_key_encrypted',
        'webhook_secret_encrypted',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'meta' => 'array',
        'api_key_encrypted' => EncryptedString::class,
        'public_key_encrypted' => EncryptedString::class,
        'webhook_secret_encrypted' => EncryptedString::class,
    ];

    /**
     * Scope to only include active gateways.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
