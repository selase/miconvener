<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Contracts\Chat\ChatProviderContract;
use RuntimeException;

final class SlackChatProvider implements ChatProviderContract
{
    public function getAuthorizationUrl(string $state): string
    {
        $clientId = config('chat.slack.client_id');
        $redirectUri = config('chat.slack.redirect_uri');
        $scopes = config('chat.slack.scopes');

        return 'https://slack.com/oauth/v2/authorize?'.http_build_query([
            'client_id' => $clientId,
            'scope' => $scopes,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    public function exchangeAuthorizationCode(string $code): array
    {
        throw new RuntimeException('Slack OAuth exchange not yet implemented.');
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        throw new RuntimeException('Slack token refresh not yet implemented.');
    }

    public function listChannels(string $token): array
    {
        throw new RuntimeException('Slack listChannels not yet implemented.');
    }

    public function postMessage(string $token, string $channelId, string $text, ?array $blocks = null): array
    {
        throw new RuntimeException('Slack postMessage not yet implemented.');
    }

    public function postDirectMessage(string $token, string $userId, string $text, ?array $blocks = null): array
    {
        throw new RuntimeException('Slack postDirectMessage not yet implemented.');
    }

    public function updateMessage(string $token, string $channelId, string $messageTs, string $text, ?array $blocks = null): array
    {
        throw new RuntimeException('Slack updateMessage not yet implemented.');
    }

    public function lookupUserByEmail(string $token, string $email): ?array
    {
        throw new RuntimeException('Slack lookupUserByEmail not yet implemented.');
    }

    public function verifyWebhookSignature(array $headers, string $body, string $signingSecret): bool
    {
        $timestamp = $headers['X-Slack-Request-Timestamp'] ?? '';
        $signature = $headers['X-Slack-Signature'] ?? '';

        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $sigBasestring = "v0:{$timestamp}:{$body}";
        $computedSignature = 'v0='.hash_hmac('sha256', $sigBasestring, $signingSecret);

        return hash_equals($computedSignature, $signature);
    }
}
