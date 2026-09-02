<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Contracts\Chat\ChatProviderContract;
use Carbon\Carbon;

final class FakeChatProvider implements ChatProviderContract
{
    /** @var list<array{method: string, args: array<mixed>}> */
    public array $calls = [];

    /** @var list<array{id: string, name: string, is_private: bool}> */
    public array $channels = [];

    /** @var array<string, array{id: string, name: string, email: string}> */
    public array $users = [];

    /** @var list<array{channel: string, text: string, blocks: ?array, ts: string}> */
    public array $messages = [];

    public function getAuthorizationUrl(string $state): string
    {
        $this->calls[] = ['method' => 'getAuthorizationUrl', 'args' => [$state]];

        return "https://auth.example.com/oauth?state={$state}&fake=true";
    }

    public function exchangeAuthorizationCode(string $code): array
    {
        $this->calls[] = ['method' => 'exchangeAuthorizationCode', 'args' => [$code]];

        return [
            'access_token' => 'xoxp-fake-access-token',
            'refresh_token' => null,
            'expires_at' => null,
            'bot_token' => 'xoxb-fake-bot-token',
            'team_id' => 'T0FAKE123',
            'team_name' => 'Fake Workspace',
            'user_id' => 'U0FAKE123',
        ];
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        $this->calls[] = ['method' => 'refreshAccessToken', 'args' => [$refreshToken]];

        return [
            'access_token' => 'xoxp-fake-refreshed-token',
            'expires_at' => Carbon::now()->addHour(),
        ];
    }

    public function listChannels(string $token): array
    {
        $this->calls[] = ['method' => 'listChannels', 'args' => [$token]];

        return $this->channels;
    }

    public function postMessage(string $token, string $channelId, string $text, ?array $blocks = null): array
    {
        $this->calls[] = ['method' => 'postMessage', 'args' => [$token, $channelId, $text, $blocks]];

        $ts = now()->timestamp.'.000100';
        $this->messages[] = ['channel' => $channelId, 'text' => $text, 'blocks' => $blocks, 'ts' => $ts];

        return ['ok' => true, 'ts' => $ts, 'channel' => $channelId];
    }

    public function postDirectMessage(string $token, string $userId, string $text, ?array $blocks = null): array
    {
        $this->calls[] = ['method' => 'postDirectMessage', 'args' => [$token, $userId, $text, $blocks]];

        $ts = now()->timestamp.'.000200';
        $this->messages[] = ['channel' => $userId, 'text' => $text, 'blocks' => $blocks, 'ts' => $ts];

        return ['ok' => true, 'ts' => $ts, 'channel' => $userId];
    }

    public function updateMessage(string $token, string $channelId, string $messageTs, string $text, ?array $blocks = null): array
    {
        $this->calls[] = ['method' => 'updateMessage', 'args' => [$token, $channelId, $messageTs, $text, $blocks]];

        return ['ok' => true, 'ts' => $messageTs];
    }

    public function lookupUserByEmail(string $token, string $email): ?array
    {
        $this->calls[] = ['method' => 'lookupUserByEmail', 'args' => [$token, $email]];

        return $this->users[$email] ?? null;
    }

    public function verifyWebhookSignature(array $headers, string $body, string $signingSecret): bool
    {
        $this->calls[] = ['method' => 'verifyWebhookSignature', 'args' => [$headers, $body, $signingSecret]];

        return true;
    }

    public function addChannel(array $channel): void
    {
        $this->channels[] = array_merge([
            'id' => 'C'.uniqid(),
            'name' => 'general',
            'is_private' => false,
        ], $channel);
    }

    public function addUser(string $email, array $user): void
    {
        $this->users[$email] = array_merge([
            'id' => 'U'.uniqid(),
            'name' => 'Fake User',
            'email' => $email,
        ], $user);
    }

    public function assertCalled(string $method, ?int $times = null): void
    {
        $calls = array_filter($this->calls, fn (array $call): bool => $call['method'] === $method);

        if ($times !== null) {
            assert(count($calls) === $times, "Expected {$method} to be called {$times} times, got ".count($calls));
        } else {
            assert(count($calls) > 0, "Expected {$method} to be called at least once");
        }
    }

    public function assertNotCalled(string $method): void
    {
        $calls = array_filter($this->calls, fn (array $call): bool => $call['method'] === $method);

        assert(count($calls) === 0, "Expected {$method} to not be called, but was called ".count($calls).' times');
    }
}
