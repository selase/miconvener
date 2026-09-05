<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventForumBan;
use App\Models\EventForumReply;
use App\Models\EventForumThread;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class EventForumController extends Controller
{
    public function index(string $subdomain, string $event): JsonResponse
    {
        $this->authorize('read event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $threads = $eventModel->forumThreads()->with(['replies.hostUser:id,first_name,last_name', 'votes', 'reports'])->get();
        $bans = $eventModel->forumBans()->orderByDesc('created_at')->get();

        return response()->json([
            'threads' => $threads->map(fn (EventForumThread $t): array => $this->threadPayload($t)),
            'bans' => $bans->map(fn (EventForumBan $b): array => [
                'id' => $b->id,
                'author_email' => $b->author_email,
                'reason' => $b->reason,
            ])->values(),
        ]);
    }

    public function reply(Request $request, string $subdomain, string $event, string $thread): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);
        $threadModel = $eventModel->forumThreads()->where('id', $thread)->firstOrFail();

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'tag' => ['nullable', Rule::in(EventForumReply::TAGS)],
        ]);

        $threadModel->replies()->create([
            'tenant_id' => $tenant->id,
            'host_user_id' => $request->user()->id,
            'author_name' => mb_trim($request->user()->first_name.' '.$request->user()->last_name),
            'body' => $validated['body'],
            'tag' => $validated['tag'] ?? null,
        ]);

        if (($validated['tag'] ?? null) === 'official_answer') {
            $threadModel->update(['is_answered' => true]);
        }

        return response()->json($this->threadPayload($threadModel->fresh(['replies.hostUser:id,first_name,last_name', 'votes', 'reports'])));
    }

    public function moderate(Request $request, string $subdomain, string $event, string $thread): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);
        $threadModel = $eventModel->forumThreads()->where('id', $thread)->firstOrFail();

        $validated = $request->validate([
            'is_pinned' => ['sometimes', 'boolean'],
            'is_hidden' => ['sometimes', 'boolean'],
        ]);

        $threadModel->update($validated);

        return response()->json($this->threadPayload($threadModel->fresh(['replies.hostUser:id,first_name,last_name', 'votes', 'reports'])));
    }

    public function destroy(string $subdomain, string $event, string $thread): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $eventModel->forumThreads()->where('id', $thread)->firstOrFail()->delete();

        return response()->json(['message' => 'Thread deleted.']);
    }

    public function ban(Request $request, string $subdomain, string $event, string $thread): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);
        $threadModel = $eventModel->forumThreads()->where('id', $thread)->firstOrFail();

        if (! $threadModel->author_email) {
            return response()->json(['message' => 'This question was posted without an email address, so it cannot be banned by author.'], 422);
        }

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        EventForumBan::updateOrCreate(
            ['event_id' => $eventModel->id, 'author_email' => $threadModel->author_email],
            ['tenant_id' => $tenant->id, 'reason' => $validated['reason'] ?? null],
        );

        $threadModel->update(['is_hidden' => true]);

        return response()->json(['message' => "{$threadModel->author_email} has been banned from this event's forum."]);
    }

    public function unban(string $subdomain, string $event, string $ban): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $eventModel->forumBans()->where('id', $ban)->firstOrFail()->delete();

        return response()->json(['message' => 'Ban lifted.']);
    }

    private function findEvent(string $tenantId, string $eventId): Event
    {
        return Event::where('tenant_id', $tenantId)->where('id', $eventId)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function threadPayload(EventForumThread $thread): array
    {
        return [
            'id' => $thread->id,
            'title' => $thread->title,
            'body' => $thread->body,
            'author_name' => $thread->author_name,
            'author_email' => $thread->author_email,
            'is_anonymous' => $thread->is_anonymous,
            'attachment_url' => $thread->attachmentUrl(),
            'attachment_name' => $thread->attachment_name,
            'is_pinned' => $thread->is_pinned,
            'is_hidden' => $thread->is_hidden,
            'is_answered' => $thread->is_answered,
            'votes_count' => $thread->votes->count(),
            'reports_count' => $thread->reports->count(),
            'created_at' => $thread->created_at->toIso8601String(),
            'replies' => $thread->replies->map(fn (EventForumReply $r): array => [
                'id' => $r->id,
                'author_name' => $r->author_name,
                'body' => $r->body,
                'tag' => $r->tag,
                'is_from_host' => $r->isFromHost(),
                'created_at' => $r->created_at->toIso8601String(),
            ])->values(),
        ];
    }

    private function getTenant()
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(403, 'Tenant context not resolved.');
        }

        return $tenant;
    }
}
