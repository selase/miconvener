<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\LlmTokenUsage;

interface LlmUsageServiceContract
{
    public function record(
        string $tenantId,
        string $provider,
        string $model,
        int $promptTokens,
        int $completionTokens,
        array $context = [],
        ?string $userId = null,
        ?string $apiKeyId = null,
        ?string $ipAddress = null,
    ): LlmTokenUsage;

    public function canConsume(string $tenantId, int $estimatedTokens = 1): bool;

    public function ensureCanConsume(string $tenantId, int $estimatedTokens = 1): void;

    public function getApiKey(string $tenantId, string $provider): ?string;

    public function isModelAllowed(string $tenantId, string $model): bool;
}
