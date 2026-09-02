<?php

declare(strict_types=1);

namespace App\Services\ProjectManagement;

use App\Contracts\ProjectManagement\PmProviderContract;
use RuntimeException;

final class MondayPmProvider implements PmProviderContract
{
    public function getAuthorizationUrl(string $state): string
    {
        $clientId = config('pm.monday.client_id');
        $redirectUri = urlencode((string) config('pm.monday.redirect_uri'));

        return "https://auth.monday.com/oauth2/authorize?client_id={$clientId}&redirect_uri={$redirectUri}&state={$state}";
    }

    public function exchangeAuthorizationCode(string $code): array
    {
        throw new RuntimeException('Monday OAuth exchange not yet implemented. Configure a real Monday.com application.');
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        throw new RuntimeException('Monday token refresh not yet implemented.');
    }

    public function listProjects(string $credential): array
    {
        throw new RuntimeException('Monday listProjects not yet implemented.');
    }

    public function createTask(string $credential, string $projectId, array $taskData): array
    {
        throw new RuntimeException('Monday createTask not yet implemented.');
    }

    public function updateTask(string $credential, string $taskId, array $taskData): array
    {
        throw new RuntimeException('Monday updateTask not yet implemented.');
    }

    public function getTask(string $credential, string $taskId): ?array
    {
        throw new RuntimeException('Monday getTask not yet implemented.');
    }

    public function deleteTask(string $credential, string $taskId): void
    {
        throw new RuntimeException('Monday deleteTask not yet implemented.');
    }

    public function registerWebhook(string $credential, string $projectId, string $callbackUrl): array
    {
        throw new RuntimeException('Monday registerWebhook not yet implemented.');
    }
}
