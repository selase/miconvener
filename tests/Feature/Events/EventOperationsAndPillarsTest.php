<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventOperationPillar;
use App\Models\EventOperationTask;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('host can view operations dashboard which auto-seeds 8 default pillars', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'science-summit-2026',
    ]);

    $response = $this->actingAs($user)
        ->getJson("http://{$host}/events/{$event->id}/operations", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertJsonStructure([
        'pillars',
        'summary' => [
            'total_tasks',
            'done_tasks',
            'in_progress_tasks',
            'blocked_tasks',
            'not_started_tasks',
            'total_estimated_budget',
            'total_actual_budget',
            'pillars_count',
        ],
        'team_members',
    ]);

    expect($event->operationPillars()->count())->toBe(8);
    $response->assertJsonPath('summary.pillars_count', 8);
});

test('organizer can create custom operational pillars on the fly', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'health-summit-2026',
    ]);

    $response = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/operations/pillars", [
            'name' => 'VIP Protocol & Dignitary Transport',
            'color' => '#E11D48',
            'icon' => 'shield-check',
        ], ['HTTP_HOST' => $host]);

    $response->assertCreated();
    $response->assertJsonPath('pillar.name', 'VIP Protocol & Dignitary Transport');
    $response->assertJsonPath('pillar.color', '#E11D48');

    expect($event->operationPillars()->where('name', 'VIP Protocol & Dignitary Transport')->exists())->toBeTrue();
});

test('organizer can create, update, change status, and delete tasks', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'global-expo-2026',
    ]);

    EventOperationPillar::seedDefaultsForEvent($event);
    $techPillar = $event->operationPillars()->where('slug', 'technology-registration')->firstOrFail();

    // 1. Store task
    $storeResponse = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/operations/tasks", [
            'pillar_id' => $techPillar->id,
            'title' => 'Deploy Badge Scanners at Hall A',
            'description' => 'Ensure 8 handheld scanners are synced with Reverb websocket channel.',
            'owner_id' => $user->id,
            'due_date' => '2026-10-15',
            'priority' => 'urgent',
            'status' => 'not_started',
            'estimated_budget' => 450.00,
            'actual_budget' => 0.00,
        ], ['HTTP_HOST' => $host]);

    $storeResponse->assertCreated();
    $taskId = $storeResponse->json('task.id');
    expect($taskId)->not->toBeNull();

    $task = EventOperationTask::findOrFail($taskId);
    expect($task->estimated_budget)->toBe(45000)
        ->and($task->priority)->toBe('urgent');

    // 2. Quick status update to in_progress
    $statusResponse = $this->actingAs($user)
        ->patchJson("http://{$host}/events/{$event->id}/operations/tasks/{$task->id}/status", [
            'status' => 'in_progress',
        ], ['HTTP_HOST' => $host]);

    $statusResponse->assertOk();
    expect($task->fresh()->status)->toBe('in_progress');

    // 3. Update to done sets completed_at
    $doneResponse = $this->actingAs($user)
        ->patchJson("http://{$host}/events/{$event->id}/operations/tasks/{$task->id}/status", [
            'status' => 'done',
        ], ['HTTP_HOST' => $host]);

    $doneResponse->assertOk();
    expect($task->fresh()->status)->toBe('done')
        ->and($task->fresh()->completed_at)->not->toBeNull();

    // 4. Delete task
    $deleteResponse = $this->actingAs($user)
        ->deleteJson("http://{$host}/events/{$event->id}/operations/tasks/{$task->id}", [], ['HTTP_HOST' => $host]);

    $deleteResponse->assertOk();
    expect(EventOperationTask::find($task->id))->toBeNull();
});

test('organizer can reorder operational pillars', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'reorder-summit-2026',
    ]);

    EventOperationPillar::seedDefaultsForEvent($event);
    $pillars = $event->operationPillars()->take(2)->get();

    $response = $this->actingAs($user)
        ->patchJson("http://{$host}/events/{$event->id}/operations/pillars/reorder", [
            'pillars' => [
                ['id' => $pillars[0]->id, 'sort_order' => 10],
                ['id' => $pillars[1]->id, 'sort_order' => 5],
            ],
        ], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($pillars[0]->fresh()->sort_order)->toBe(10)
        ->and($pillars[1]->fresh()->sort_order)->toBe(5);
});
