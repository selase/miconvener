<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EncryptedString;
use App\Enum\ChatNotificationType;
use App\Enum\ChatProvider;
use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ChatIntegration extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuid;
    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $casts = [
        'provider' => ChatProvider::class,
        'bot_token_encrypted' => EncryptedString::class,
        'access_token_encrypted' => EncryptedString::class,
        'refresh_token_encrypted' => EncryptedString::class,
        'token_expires_at' => 'datetime',
        'notification_settings' => 'array',
        'is_active' => 'boolean',
        'settings' => 'array',
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

    public function scopeByProvider($query, ChatProvider $provider)
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

    public function isNotificationEnabled(ChatNotificationType $type): bool
    {
        $settings = $this->notification_settings ?? [];

        return (bool) ($settings[$type->value] ?? true);
    }

    public function getPostToken(): string
    {
        return $this->bot_token_encrypted ?? $this->access_token_encrypted ?? '';
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
