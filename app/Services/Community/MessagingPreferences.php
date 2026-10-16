<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\CommunityMessagePreference;
use App\Models\Tenant;
use InvalidArgumentException;

final class MessagingPreferences
{
    public function normalise(string $channel, string $address): ?string
    {
        $identity = app(MemberIdentity::class);
        if ($channel === 'sms') {
            return $identity->normalisePhone($address);
        }
        if ($channel !== 'email') {
            return null;
        }
        $email = $identity->normaliseEmail($address);

        return $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    public function hash(string $channel, string $address): string
    {
        $address = $this->normalise($channel, $address) ?? throw new InvalidArgumentException('Invalid channel address.');
        $key = hash_hmac('sha256', 'community-message-addresses:v1', (string) config('app.key'), true);

        return hash_hmac('sha256', $channel.':'.$address, $key);
    }

    public function allowsMarketing(Tenant $tenant, string $channel, string $address): bool
    {
        if ($this->normalise($channel, $address) === null) {
            return false;
        }

        return ! CommunityMessagePreference::query()->where('tenant_id', $tenant->id)->where('channel', $channel)
            ->where('address_hash', $this->hash($channel, $address))->whereNotNull('marketing_disabled_at')->exists();
    }

    public function disableMarketing(Tenant $tenant, string $channel, string $address): void
    {
        $this->preference($tenant, $channel, $address)->update(['marketing_disabled_at' => now()]);
    }

    public function token(Tenant $tenant, string $channel, string $address): string
    {
        $token = bin2hex(random_bytes(32));
        $preference = $this->preference($tenant, $channel, $address);
        $preference->tokens()->create(['token_hash' => hash('sha256', $token)]);

        return $token;
    }

    public function forToken(Tenant $tenant, string $token): CommunityMessagePreference
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token) === 1, 404);

        return CommunityMessagePreference::query()->where('tenant_id', $tenant->id)
            ->whereHas('tokens', fn ($query) => $query->where('token_hash', hash('sha256', $token)))->firstOrFail();
    }

    private function preference(Tenant $tenant, string $channel, string $address): CommunityMessagePreference
    {
        $normalized = $this->normalise($channel, $address) ?? throw new InvalidArgumentException('Invalid channel address.');

        return CommunityMessagePreference::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'channel' => $channel, 'address_hash' => $this->hash($channel, $normalized)],
            ['address' => $normalized],
        );
    }
}
