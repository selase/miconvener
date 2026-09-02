<?php

declare(strict_types=1);

namespace App\Services\ProjectManagement;

use App\Contracts\ProjectManagement\PmProviderContract;
use RuntimeException;

final class JiraPmProvider implements PmProviderContract
{
    public function getAuthorizationUrl(string $state): string
    {
        $clientId = config('pm.jira.client_id');
        $redirectUri = urlencode((string) config('pm.jira.redirect_uri'));
        $scopes = urlencode((string) config('pm.jira.scopes'));

        return "https://auth.atlassian.com/authorize?audience=api.atlassian.com&client_id={$clientId}&scope={$scopes}&redirect_uri={$redirectUri}&state={$state}&response_type=code&prompt=consent";
    }

    public function exchangeAuthorizationCode(string $code): array
    {
        throw new RuntimeException('Jira OAuth exchange not yet implemented. Configure a real Jira Cloud application.');
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        throw new RuntimeException('Jira token refresh not yet implemented.');
    }

    public function listProjects(string $credential): array
    {
        throw new RuntimeException('Jira listProjects not yet implemented.');
    }

    public function createTask(string $credential, string $projectId, array $taskData): array
    {
        throw new RuntimeException('Jira createTask not yet implemented.');
    }

    public function updateTask(string $credential, string $taskId, array $taskData): array
    {
        throw new RuntimeException('Jira updateTask not yet implemented.');
    }

    public function getTask(string $credential, string $taskId): ?array
    {
        throw new RuntimeException('Jira getTask not yet implemented.');
    }

    public function deleteTask(string $credential, string $taskId): void
    {
        throw new RuntimeException('Jira deleteTask not yet implemented.');
    }

    public function registerWebhook(string $credential, string $projectId, string $callbackUrl): array
    {
        throw new RuntimeException('Jira registerWebhook not yet implemented.');
    }
}
