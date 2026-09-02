<?php

declare(strict_types=1);

namespace App\Services\ProjectManagement;

use App\Contracts\ProjectManagement\PmProviderContract;
use Carbon\Carbon;

final class FakePmProvider implements PmProviderContract
{
    /** @var list<array{method: string, args: array<mixed>}> */
    public array $calls = [];

    /** @var list<array{id: string, name: string, key: ?string}> */
    public array $projects = [];

    /** @var array<string, array{id: string, status: string, assignee: ?string, updated_at: string}> */
    public array $tasks = [];

    public ?string $nextWebhookId = 'fake-webhook-123';

    public function getAuthorizationUrl(string $state): string
    {
        $this->calls[] = ['method' => 'getAuthorizationUrl', 'args' => [$state]];

        return "https://auth.example.com/oauth?state={$state}&fake=true";
    }

    public function exchangeAuthorizationCode(string $code): array
    {
        $this->calls[] = ['method' => 'exchangeAuthorizationCode', 'args' => [$code]];

        return [
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'expires_at' => Carbon::now()->addHour(),
            'account_id' => 'fake-account-id',
            'account_name' => 'Fake Account',
            'email' => 'user@example.com',
        ];
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        $this->calls[] = ['method' => 'refreshAccessToken', 'args' => [$refreshToken]];

        return [
            'access_token' => 'fake-refreshed-access-token',
            'expires_at' => Carbon::now()->addHour(),
        ];
    }

    public function listProjects(string $credential): array
    {
        $this->calls[] = ['method' => 'listProjects', 'args' => [$credential]];

        return $this->projects;
    }

    public function createTask(string $credential, string $projectId, array $taskData): array
    {
        $this->calls[] = ['method' => 'createTask', 'args' => [$credential, $projectId, $taskData]];

        $id = 'fake-task-'.uniqid();

        return [
            'id' => $id,
            'key' => 'FAKE-'.random_int(1, 999),
            'url' => "https://pm.example.com/task/{$id}",
        ];
    }

    public function updateTask(string $credential, string $taskId, array $taskData): array
    {
        $this->calls[] = ['method' => 'updateTask', 'args' => [$credential, $taskId, $taskData]];

        return [
            'id' => $taskId,
            'url' => "https://pm.example.com/task/{$taskId}",
        ];
    }

    public function getTask(string $credential, string $taskId): ?array
    {
        $this->calls[] = ['method' => 'getTask', 'args' => [$credential, $taskId]];

        return $this->tasks[$taskId] ?? null;
    }

    public function deleteTask(string $credential, string $taskId): void
    {
        $this->calls[] = ['method' => 'deleteTask', 'args' => [$credential, $taskId]];
    }

    public function registerWebhook(string $credential, string $projectId, string $callbackUrl): array
    {
        $this->calls[] = ['method' => 'registerWebhook', 'args' => [$credential, $projectId, $callbackUrl]];

        return [
            'id' => $this->nextWebhookId ?? 'fake-webhook-'.uniqid(),
            'secret' => 'fake-webhook-secret',
        ];
    }

    /**
     * Add a fake project that will be returned by listProjects.
     */
    public function addProject(array $project): void
    {
        $this->projects[] = array_merge([
            'id' => 'fake-proj-'.uniqid(),
            'name' => 'Fake Project',
            'key' => null,
        ], $project);
    }

    /**
     * Add a fake task that will be returned by getTask.
     */
    public function addTask(string $taskId, array $task): void
    {
        $this->tasks[$taskId] = array_merge([
            'id' => $taskId,
            'status' => 'open',
            'assignee' => null,
            'updated_at' => now()->toIso8601String(),
        ], $task);
    }

    /**
     * Assert a method was called with optional count check.
     */
    public function assertCalled(string $method, ?int $times = null): void
    {
        $calls = array_filter($this->calls, fn (array $call): bool => $call['method'] === $method);

        if ($times !== null) {
            assert(count($calls) === $times, "Expected {$method} to be called {$times} times, got ".count($calls));
        } else {
            assert(count($calls) > 0, "Expected {$method} to be called at least once");
        }
    }

    /**
     * Assert a method was never called.
     */
    public function assertNotCalled(string $method): void
    {
        $calls = array_filter($this->calls, fn (array $call): bool => $call['method'] === $method);

        assert(count($calls) === 0, "Expected {$method} to not be called, but was called ".count($calls).' times');
    }
}
