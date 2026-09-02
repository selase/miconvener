<?php

declare(strict_types=1);

namespace App\Services\ProjectManagement;

use App\Contracts\ProjectManagement\PmProviderContract;
use RuntimeException;

final class LinearPmProvider implements PmProviderContract
{
    public function getAuthorizationUrl(string $state): string
    {
        throw new RuntimeException('Linear uses API key authentication, not OAuth.');
    }

    public function exchangeAuthorizationCode(string $code): array
    {
        throw new RuntimeException('Linear uses API key authentication, not OAuth.');
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        throw new RuntimeException('Linear uses API key authentication, not OAuth.');
    }

    public function listProjects(string $credential): array
    {
        throw new RuntimeException('Linear listProjects not yet implemented.');
    }

    public function createTask(string $credential, string $projectId, array $taskData): array
    {
        throw new RuntimeException('Linear createTask not yet implemented.');
    }

    public function updateTask(string $credential, string $taskId, array $taskData): array
    {
        throw new RuntimeException('Linear updateTask not yet implemented.');
    }

    public function getTask(string $credential, string $taskId): ?array
    {
        throw new RuntimeException('Linear getTask not yet implemented.');
    }

    public function deleteTask(string $credential, string $taskId): void
    {
        throw new RuntimeException('Linear deleteTask not yet implemented.');
    }

    public function registerWebhook(string $credential, string $projectId, string $callbackUrl): array
    {
        throw new RuntimeException('Linear registerWebhook not yet implemented.');
    }
}
