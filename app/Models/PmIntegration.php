<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EncryptedString;
use App\Enum\PmProvider;
use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PmIntegration extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuid;
    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $casts = [
        'provider' => PmProvider::class,
        'access_token_encrypted' => EncryptedString::class,
        'refresh_token_encrypted' => EncryptedString::class,
        'api_key_encrypted' => EncryptedString::class,
        'token_expires_at' => 'datetime',
        'is_active' => 'boolean',
        'auto_push' => 'boolean',
        'settings' => 'array',
        'status_mapping' => 'array',
        'last_synced_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByProvider($query, PmProvider $provider)
    {
        return $query->where('provider', $provider);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }

    public function usesOAuth(): bool
    {
        return $this->auth_type === 'oauth';
    }

    public function getCredential(): string
    {
        if ($this->usesOAuth()) {
            return $this->access_token_encrypted ?? '';
        }

        return $this->api_key_encrypted ?? '';
    }

    public function markSynced(): void
    {
        $this->update([
            'last_synced_at' => now(),
            'last_error_at' => null,
            'last_error_message' => null,
        ]);
    }

    public function markError(string $message): void
    {
        $this->update([
            'last_error_at' => now(),
            'last_error_message' => $message,
        ]);
    }
}
