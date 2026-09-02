<?php

declare(strict_types=1);

namespace App\Contracts\ProjectManagement;

interface PmProviderContract
{
    /**
     * Get the OAuth authorization URL for the user to grant access.
     */
    public function getAuthorizationUrl(string $state): string;

    /**
     * Exchange an authorization code for access + refresh tokens.
     *
     * @return array{access_token: string, refresh_token: string, expires_at: \Carbon\Carbon, account_id: string, account_name: string, email: ?string}
     */
    public function exchangeAuthorizationCode(string $code): array;

    /**
     * Refresh an expired access token.
     *
     * @return array{access_token: string, expires_at: \Carbon\Carbon}
     */
    public function refreshAccessToken(string $refreshToken): array;

    /**
     * List available projects/boards the user can push action items to.
     *
     * @return list<array{id: string, name: string, key: ?string}>
     */
    public function listProjects(string $credential): array;

    /**
     * Create a task/issue in the external PM tool.
     *
     * @return array{id: string, key: ?string, url: ?string}
     */
    public function createTask(string $credential, string $projectId, array $taskData): array;

    /**
     * Update an existing task in the external PM tool.
     *
     * @return array{id: string, url: ?string}
     */
    public function updateTask(string $credential, string $taskId, array $taskData): array;

    /**
     * Get a task's current state from the external PM tool.
     *
     * @return array{id: string, status: string, assignee: ?string, updated_at: string}|null
     */
    public function getTask(string $credential, string $taskId): ?array;

    /**
     * Delete a task from the external PM tool.
     */
    public function deleteTask(string $credential, string $taskId): void;

    /**
     * Register a webhook for status change notifications.
     *
     * @return array{id: string, secret: ?string}
     */
    public function registerWebhook(string $credential, string $projectId, string $callbackUrl): array;
}
