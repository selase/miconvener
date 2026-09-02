<?php

declare(strict_types=1);

namespace App\Contracts\Chat;

interface ChatProviderContract
{
    /**
     * Get the OAuth authorization URL for the user to grant access.
     */
    public function getAuthorizationUrl(string $state): string;

    /**
     * Exchange an authorization code for access + refresh tokens.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_at: ?\Carbon\Carbon, bot_token: ?string, team_id: string, team_name: string, user_id: ?string}
     */
    public function exchangeAuthorizationCode(string $code): array;

    /**
     * Refresh an expired access token.
     *
     * @return array{access_token: string, expires_at: \Carbon\Carbon}
     */
    public function refreshAccessToken(string $refreshToken): array;

    /**
     * List available channels the bot can post to.
     *
     * @return list<array{id: string, name: string, is_private: bool}>
     */
    public function listChannels(string $token): array;

    /**
     * Post a message to a channel.
     *
     * @return array{ok: bool, ts: ?string, channel: ?string}
     */
    public function postMessage(string $token, string $channelId, string $text, ?array $blocks = null): array;

    /**
     * Send a direct message to a user.
     *
     * @return array{ok: bool, ts: ?string, channel: ?string}
     */
    public function postDirectMessage(string $token, string $userId, string $text, ?array $blocks = null): array;

    /**
     * Update an existing message.
     *
     * @return array{ok: bool, ts: ?string}
     */
    public function updateMessage(string $token, string $channelId, string $messageTs, string $text, ?array $blocks = null): array;

    /**
     * Look up a user by email address.
     *
     * @return array{id: string, name: string, email: string}|null
     */
    public function lookupUserByEmail(string $token, string $email): ?array;

    /**
     * Verify an incoming webhook signature.
     */
    public function verifyWebhookSignature(array $headers, string $body, string $signingSecret): bool;
}
