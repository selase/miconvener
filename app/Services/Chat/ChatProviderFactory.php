<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Contracts\Chat\ChatProviderContract;
use App\Enum\ChatProvider;

final class ChatProviderFactory
{
    public function make(ChatProvider|string $provider): ChatProviderContract
    {
        $providerEnum = $provider instanceof ChatProvider
            ? $provider
            : ChatProvider::from($provider);

        return match ($providerEnum) {
            ChatProvider::Slack => app(SlackChatProvider::class),
            ChatProvider::Teams => app(TeamsChatProvider::class),
        };
    }
}
