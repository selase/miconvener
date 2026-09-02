<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Contracts\Chat\ChatProviderContract;
use RuntimeException;

final class TeamsChatProvider implements ChatProviderContract
{
    public function getAuthorizationUrl(string $state): string
    {
        $clientId = config('chat.teams.client_id');
        $redirectUri = config('chat.teams.redirect_uri');
        $tenantId = config('chat.teams.tenant_id', 'common');

        return "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/authorize?".http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => 'ChannelMessage.Send Chat.ReadWrite User.Read offline_access',
            'state' => $state,
        ]);
    }

    public function exchangeAuthorizationCode(string $code): array
    {
        throw new RuntimeException('Teams OAuth exchange not yet implemented.');
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        throw new RuntimeException('Teams token refresh not yet implemented.');
    }

    public function listChannels(string $token): array
    {
        throw new RuntimeException('Teams listChannels not yet implemented.');
    }

    public function postMessage(string $token, string $channelId, string $text, ?array $blocks = null): array
    {
        throw new RuntimeException('Teams postMessage not yet implemented.');
    }

    public function postDirectMessage(string $token, string $userId, string $text, ?array $blocks = null): array
    {
        throw new RuntimeException('Teams postDirectMessage not yet implemented.');
    }

    public function updateMessage(string $token, string $channelId, string $messageTs, string $text, ?array $blocks = null): array
    {
        throw new RuntimeException('Teams updateMessage not yet implemented.');
    }

    public function lookupUserByEmail(string $token, string $email): ?array
    {
        throw new RuntimeException('Teams lookupUserByEmail not yet implemented.');
    }

    public function verifyWebhookSignature(array $headers, string $body, string $signingSecret): bool
    {
        throw new RuntimeException('Teams webhook verification not yet implemented.');
    }
}
