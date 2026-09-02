<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Models\TenantApiKey;
use Illuminate\Support\Str;

final class ApiKeyService
{
    /**
     * Generate a new API key for a tenant.
     *
     * @return array{key: string, model: TenantApiKey}
     */
    public function generate(
        string $tenantId,
        string|int|null $userId,
        string $name,
        ?array $scopes = [],
        ?array $ipRestrictions = []
    ): array {
        $plainKey = 'sk_'.Str::random(40);
        $hash = hash('sha256', $plainKey);
        $hint = mb_substr($plainKey, -4);

        $apiKey = TenantApiKey::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'name' => $name,
            'key_hash' => $hash,
            'key_hint' => $hint,
            'scopes' => $scopes,
            'ip_restrictions' => $ipRestrictions,
        ]);

        return [
            'key' => $plainKey,
            'model' => $apiKey,
        ];
    }

    /**
     * Authenticate an API key.
     */
    public function authenticate(string $plainKey): ?TenantApiKey
    {
        $hash = hash('sha256', $plainKey);

        $key = TenantApiKey::where('key_hash', $hash)
            ->whereNull('revoked_at')
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();

        if ($key) {
            $key->update(['last_used_at' => now()]);
        }

        return $key;
    }
}
