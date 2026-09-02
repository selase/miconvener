<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Tenancy\TenantContext;
use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

final class TenantLlmConfig extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuid;
    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Helper to get decrypted key
     */
    public function getDecryptedKey(): string
    {
        return Crypt::decryptString($this->api_key_encrypted);
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        self::creating(function ($model): void {
            if (! $model->tenant_id && $tenant = app(TenantContext::class)->getTenant()) {
                $model->tenant_id = $tenant->id;
            }
        });
    }

    /**
     * Interact with the API Key.
     */
    protected function apiKeyEncrypted(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value, // Access raw encrypted value if needed
            set: fn ($value) => Crypt::encryptString($value),
        );
    }
}
