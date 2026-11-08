<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventOperationPillar;
use App\Models\EventOperationTask;
use App\Models\User;
use App\Services\Events\EventStaffScope;
use App\Services\Events\EventTeamScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class EventOperationController extends Controller
{
    public function index(Request $request, string $subdomain, Event $event): JsonResponse
    {
        Gate::authorize('read event-operation');

        EventOperationPillar::seedDefaultsForEvent($event);

        $pillars = EventOperationPillar::query()->where('event_id', $event->id)->orderBy('sort_order')
            ->with([
                'tasks' => function ($query) use ($request, $event): void {
                    $query->with(['owner' => fn ($query) => $query->select(['id', 'first_name', 'last_name', 'email'])->when(EventStaffScope::isEventOrganiser($request->user(), (string) $event->tenant_id), fn ($query) => $query->whereIn('id', $this->allowedOwnerIds($request, $event))), 'dependencyTask:id,title,status'])
                        ->orderBy('due_date')
                        ->orderBy('created_at');
                },
            ])
            ->get();

        $allTasks = $event->operationTasks()->get();

        $summary = [
            'total_tasks' => $allTasks->count(),
            'done_tasks' => $allTasks->where('status', EventOperationTask::STATUS_DONE)->count(),
            'in_progress_tasks' => $allTasks->where('status', EventOperationTask::STATUS_IN_PROGRESS)->count(),
            'blocked_tasks' => $allTasks->where('status', EventOperationTask::STATUS_BLOCKED)->count(),
            'not_started_tasks' => $allTasks->where('status', EventOperationTask::STATUS_NOT_STARTED)->count(),
            'total_estimated_budget' => (int) $allTasks->sum('estimated_budget'),
            'total_actual_budget' => (int) $allTasks->sum('actual_budget'),
            'pillars_count' => $pillars->count(),
        ];

        $canManageBudgets = ! EventStaffScope::isEventOrganiser($request->user(), (string) $event->tenant_id);
        if (! $canManageBudgets) {
            unset($summary['total_estimated_budget'], $summary['total_actual_budget']);
        }
        foreach ($pillars as $pillar) {
            $canDeletePillar = true;
            foreach ($pillar->tasks as $task) {
                $canDeleteTask = $canManageBudgets || ((int) $task->getRawOriginal('estimated_budget') === 0 && (int) $task->getRawOriginal('actual_budget') === 0);
                $task->setAttribute('can_delete', $canDeleteTask);
                $canDeletePillar = $canDeletePillar && $canDeleteTask;
                if (! $canManageBudgets) {
                    $task->makeHidden(['estimated_budget', 'actual_budget']);
                }
            }
            $pillar->setAttribute('can_delete', $canDeletePillar);
        }

        $teamMembers = $event->tenant?->users()
            ? $event->tenant->users()->when(EventStaffScope::isEventOrganiser($request->user(), (string) $event->tenant_id), fn ($query) => $query->whereIn('users.id', $this->allowedOwnerIds($request, $event)))->select('users.id', 'users.first_name', 'users.last_name', 'users.email')->get()->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
            ])
            : collect();

        return response()->json([
            'pillars' => $pillars,
            'summary' => $summary,
            'can_manage_budgets' => $canManageBudgets,
            'team_members' => $teamMembers,
        ]);
    }

    public function storePillar(Request $request, string $subdomain, Event $event): JsonResponse
    {
        Gate::authorize('create event-operation');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:30'],
            'icon' => ['nullable', 'string', 'max:50'],
        ]);

        $baseSlug = Str::slug($validated['name']);
        $slug = $baseSlug;
        $counter = 1;

        while ($event->operationPillars()->where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        $maxSort = (int) ($event->operationPillars()->max('sort_order') ?? 0);

        $pillar = $event->operationPillars()->create([
            'tenant_id' => $event->tenant_id,
            'name' => $validated['name'],
            'slug' => $slug,
            'color' => $validated['color'] ?? '#3B82F6',
            'icon' => $validated['icon'] ?? 'folder',
            'is_default' => false,
            'sort_order' => $maxSort + 1,
        ]);

        return response()->json([
            'message' => 'Pillar created successfully.',
            'pillar' => $pillar->load('tasks')->setAttribute('can_delete', true),
        ], 201);
    }

    public function updatePillar(Request $request, string $subdomain, Event $event, EventOperationPillar $pillar): JsonResponse
    {
        abort_unless((string) $pillar->event_id === (string) $event->id, 404);

        Gate::authorize('update event-operation');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:30'],
            'icon' => ['nullable', 'string', 'max:50'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $pillar->update($validated);

        return response()->json([
            'message' => 'Pillar updated successfully.',
            'pillar' => $pillar->setAttribute('can_delete', ! EventStaffScope::isEventOrganiser($request->user(), (string) $event->tenant_id) || ! $this->pillarHasRecordedBudget($pillar)),
        ]);
    }

    public function destroyPillar(Request $request, string $subdomain, Event $event, EventOperationPillar $pillar): JsonResponse
    {
        abort_unless((string) $pillar->event_id === (string) $event->id, 404);

        Gate::authorize('delete event-operation');

        abort_if(EventStaffScope::isEventOrganiser($request->user(), (string) $event->tenant_id) && $this->pillarHasRecordedBudget($pillar), 403, 'Ask an organisation administrator to remove a record with financial values.');

        $pillar->delete();

        return response()->json([
            'message' => 'Pillar deleted successfully.',
        ]);
    }

    public function reorderPillars(Request $request, string $subdomain, Event $event): JsonResponse
    {
        Gate::authorize('manage operation-pillars');

        $validated = $request->validate([
            'pillars' => ['required', 'array'],
            'pillars.*.id' => ['required', 'uuid', Rule::exists('landlord.event_operation_pillars', 'id')->where('event_id', $event->id)],
            'pillars.*.sort_order' => ['required', 'integer'],
        ]);

        foreach ($validated['pillars'] as $item) {
            $event->operationPillars()->where('id', $item['id'])->update(['sort_order' => $item['sort_order']]);
        }

        return response()->json([
            'message' => 'Pillars reordered successfully.',
        ]);
    }

    public function storeTask(Request $request, string $subdomain, Event $event): JsonResponse
    {
        Gate::authorize('create event-operation');

        $validated = $request->validate([
            'pillar_id' => ['required', 'uuid', Rule::exists('landlord.event_operation_pillars', 'id')->where('event_id', $event->id)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'owner_id' => ['nullable', 'integer', EventStaffScope::isEventOrganiser($request->user(), (string) $event->tenant_id) ? Rule::in($this->allowedOwnerIds($request, $event)) : 'exists:users,id'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
            'priority' => ['nullable', 'string', 'in:low,medium,high,urgent'],
            'status' => ['nullable', 'string', 'in:not_started,in_progress,blocked,done'],
            'dependency_task_id' => ['nullable', 'uuid', Rule::exists('landlord.event_operation_tasks', 'id')->where('event_id', $event->id)],
            'estimated_budget' => [EventStaffScope::isEventOrganiser($request->user(), (string) $event->tenant_id) ? 'prohibited' : 'nullable', 'numeric', 'min:0'],
            'actual_budget' => [EventStaffScope::isEventOrganiser($request->user(), (string) $event->tenant_id) ? 'prohibited' : 'nullable', 'numeric', 'min:0'],
        ]);

        $ownerName = $validated['owner_name'] ?? null;
        if (! empty($validated['owner_id']) && empty($ownerName)) {
            $owner = User::find($validated['owner_id']);
            $ownerName = $owner?->name;
        }

        $status = $validated['status'] ?? EventOperationTask::STATUS_NOT_STARTED;
        $completedAt = $status === EventOperationTask::STATUS_DONE ? now() : null;

        $task = EventOperationTask::query()->create([
            'event_id' => $event->id,
            'tenant_id' => $event->tenant_id,
            'pillar_id' => $validated['pillar_id'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'owner_id' => $validated['owner_id'] ?? null,
            'owner_name' => $ownerName,
            'due_date' => $validated['due_date'] ?? null,
            'priority' => $validated['priority'] ?? EventOperationTask::PRIORITY_MEDIUM,
            'status' => $status,
            'dependency_task_id' => $validated['dependency_task_id'] ?? null,
            'estimated_budget' => (int) round(((float) ($validated['estimated_budget'] ?? 0)) * 100),
            'actual_budget' => (int) round(((float) ($validated['actual_budget'] ?? 0)) * 100),
            'completed_at' => $completedAt,
        ]);

        return response()->json([
            'message' => 'Task created successfully.',
            'task' => $this->taskPayload($request, $event, $task->refresh()),
        ], 201);
    }

    public function updateTask(Request $request, string $subdomain, Event $event, EventOperationTask $task): JsonResponse
    {
        abort_unless((string) $task->event_id === (string) $event->id, 404);

        Gate::authorize('update event-operation');

        $validated = $request->validate([
            'pillar_id' => ['sometimes', 'required', 'uuid', Rule::exists('landlord.event_operation_pillars', 'id')->where('event_id', $event->id)],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'owner_id' => ['nullable', 'integer', EventStaffScope::isEventOrganiser($request->user(), (string) $event->tenant_id) ? Rule::in($this->allowedOwnerIds($request, $event)) : 'exists:users,id'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
            'priority' => ['nullable', 'string', 'in:low,medium,high,urgent'],
            'status' => ['nullable', 'string', 'in:not_started,in_progress,blocked,done'],
            'dependency_task_id' => ['nullable', 'uuid', Rule::exists('landlord.event_operation_tasks', 'id')->where('event_id', $event->id)],
            'estimated_budget' => [EventStaffScope::isEventOrganiser($request->user(), (string) $event->tenant_id) ? 'prohibited' : 'nullable', 'numeric', 'min:0'],
            'actual_budget' => [EventStaffScope::isEventOrganiser($request->user(), (string) $event->tenant_id) ? 'prohibited' : 'nullable', 'numeric', 'min:0'],
        ]);

        if (array_key_exists('owner_id', $validated)) {
            if (! empty($validated['owner_id']) && empty($validated['owner_name'])) {
                $owner = User::find($validated['owner_id']);
                $validated['owner_name'] = $owner?->name;
            } elseif (empty($validated['owner_id'])) {
                $validated['owner_name'] = null;
            }
        }

        if (array_key_exists('estimated_budget', $validated)) {
            $validated['estimated_budget'] = (int) round(((float) ($validated['estimated_budget'] ?? 0)) * 100);
        }

        if (array_key_exists('actual_budget', $validated)) {
            $validated['actual_budget'] = (int) round(((float) ($validated['actual_budget'] ?? 0)) * 100);
        }

        if (isset($validated['status'])) {
            if ($validated['status'] === EventOperationTask::STATUS_DONE && $task->status !== EventOperationTask::STATUS_DONE) {
                $validated['completed_at'] = now();
            } elseif ($validated['status'] !== EventOperationTask::STATUS_DONE) {
                $validated['completed_at'] = null;
            }
        }

        $task->update($validated);

        return response()->json([
            'message' => 'Task updated successfully.',
            'task' => $this->taskPayload($request, $event, $task->refresh()),
        ]);
    }

    public function updateTaskStatus(Request $request, string $subdomain, Event $event, EventOperationTask $task): JsonResponse
    {
        abort_unless((string) $task->event_id === (string) $event->id, 404);

        Gate::authorize('update event-operation');

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:not_started,in_progress,blocked,done'],
        ]);

        $status = $validated['status'];
        $completedAt = $status === EventOperationTask::STATUS_DONE ? now() : null;

        $task->update([
            'status' => $status,
            'completed_at' => $completedAt,
        ]);

        return response()->json([
            'message' => 'Task status updated.',
            'task' => $this->taskPayload($request, $event, $task->refresh()),
        ]);
    }

    public function destroyTask(Request $request, string $subdomain, Event $event, EventOperationTask $task): JsonResponse
    {
        abort_unless((string) $task->event_id === (string) $event->id, 404);

        Gate::authorize('delete event-operation');

        abort_if(EventStaffScope::isEventOrganiser($request->user(), (string) $event->tenant_id) && ((int) $task->estimated_budget !== 0 || (int) $task->actual_budget !== 0), 403, 'Ask an organisation administrator to remove a record with financial values.');

        $task->delete();

        return response()->json([
            'message' => 'Task deleted successfully.',
        ]);
    }

    private function pillarHasRecordedBudget(EventOperationPillar $pillar): bool
    {
        return EventOperationTask::query()->where('pillar_id', $pillar->id)
            ->where(fn ($query) => $query->where('estimated_budget', '!=', 0)->orWhere('actual_budget', '!=', 0))->exists();
    }

    private function taskPayload(Request $request, Event $event, EventOperationTask $task): EventOperationTask
    {
        $organiser = EventStaffScope::isEventOrganiser($request->user(), (string) $event->tenant_id);
        $task->load([
            'owner' => fn ($query) => $query->select(['id', 'first_name', 'last_name', 'email'])->when($organiser, fn ($query) => $query->whereIn('id', $this->allowedOwnerIds($request, $event))),
            'dependencyTask:id,title,status',
        ]);

        $task->setAttribute('can_delete', ! $organiser || ((int) $task->estimated_budget === 0 && (int) $task->actual_budget === 0));

        return $organiser ? $task->makeHidden(['estimated_budget', 'actual_budget']) : $task;
    }

    /** @return list<int|string> */
    private function allowedOwnerIds(Request $request, Event $event): array
    {
        return EventTeamScope::eligibleIds($request->user(), $event, $event->operationTasks()->whereNotNull('owner_id')->pluck('owner_id'));
    }
}
