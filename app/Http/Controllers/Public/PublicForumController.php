<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Libraries\Helper;
use App\Models\Event;
use App\Models\EventForumBan;
use App\Models\EventForumReply;
use App\Models\EventForumThread;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

final class PublicForumController extends Controller
{
    public function index(Request $request, string $subdomain, string $event): JsonResponse
    {
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('slug', $event)->published()->firstOrFail();

        // The forum of a closed event is as revealing as its lineup: who is
        // asking what, under their own names. There is no registration in this
        // route to check against, so a private event has no public forum.
        if ($eventModel->isPrivate()) {
            abort(404);
        }

        $respondentToken = (string) $request->query('respondent_token', '');

        $threads = $eventModel->forumThreads()->visible()->with(['replies', 'votes'])->get();

        return response()->json($threads->map(fn (EventForumThread $t): array => [
            'id' => $t->id,
            'title' => $t->title,
            'body' => $t->body,
            'author_name' => $t->is_anonymous ? 'Anonymous' : $t->author_name,
            'is_answered' => $t->is_answered,
            'attachment_url' => $t->attachmentUrl(),
            'attachment_name' => $t->attachment_name,
            'votes_count' => $t->votes->count(),
            'voted_by_me' => $respondentToken !== '' && $t->votes->contains('respondent_token', $respondentToken),
            'created_at' => $t->created_at->toIso8601String(),
            'replies' => $t->replies->map(fn (EventForumReply $r): array => [
                'author_name' => $r->author_name,
                'body' => $r->body,
                'tag' => $r->tag,
                'is_from_host' => $r->isFromHost(),
                'attachment_url' => $r->attachmentUrl(),
                'attachment_name' => $r->attachment_name,
                'created_at' => $r->created_at->toIso8601String(),
            ])->values(),
        ]));
    }

    public function store(Request $request, string $subdomain, string $event): RedirectResponse
    {
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('slug', $event)->published()->firstOrFail();

        // The forum of a closed event is as revealing as its lineup: who is
        // asking what, under their own names. There is no registration in this
        // route to check against, so a private event has no public forum.
        if ($eventModel->isPrivate()) {
            abort(404);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'author_name' => ['required', 'string', 'max:255'],
            'author_email' => ['nullable', 'email', 'max:255'],
            'is_anonymous' => ['sometimes', 'boolean'],
            'attachment' => ['nullable', 'file', 'max:10240'],
        ]);

        if (! empty($validated['author_email'])
            && EventForumBan::where('event_id', $eventModel->id)->where('author_email', $validated['author_email'])->exists()) {
            return redirect()->back()->with('error', "You're no longer able to post in this event's forum.");
        }

        $attachment = [];
        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $attachment = [
                'attachment_path' => Helper::processUploadedFile($request, 'attachment', 'forum', 'event-forum', config('app.env') === 'production' ? 's3' : 'public'),
                'attachment_name' => $file->getClientOriginalName(),
                'attachment_size' => $file->getSize(),
            ];
        }

        $eventModel->forumThreads()->create([
            ...Arr::except($validated, 'attachment'),
            ...$attachment,
            'tenant_id' => $tenant->id,
            'is_anonymous' => (bool) ($validated['is_anonymous'] ?? false),
        ]);

        return redirect()->back()->with('success', 'Your question has been posted.');
    }

    public function vote(Request $request, string $subdomain, string $event, string $thread): JsonResponse
    {
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('slug', $event)->published()->firstOrFail();

        // The forum of a closed event is as revealing as its lineup: who is
        // asking what, under their own names. There is no registration in this
        // route to check against, so a private event has no public forum.
        if ($eventModel->isPrivate()) {
            abort(404);
        }
        $threadModel = $eventModel->forumThreads()->visible()->where('id', $thread)->firstOrFail();

        $validated = $request->validate(['respondent_token' => ['required', 'string', 'max:64']]);

        $exists = $threadModel->votes()->where('respondent_token', $validated['respondent_token'])->exists();
        if ($exists) {
            return response()->json(['message' => 'Already upvoted.'], 422);
        }

        $threadModel->votes()->create(['tenant_id' => $tenant->id, 'respondent_token' => $validated['respondent_token']]);

        return response()->json(['votes_count' => $threadModel->votes()->count()]);
    }

    public function unvote(Request $request, string $subdomain, string $event, string $thread): JsonResponse
    {
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('slug', $event)->published()->firstOrFail();

        // The forum of a closed event is as revealing as its lineup: who is
        // asking what, under their own names. There is no registration in this
        // route to check against, so a private event has no public forum.
        if ($eventModel->isPrivate()) {
            abort(404);
        }
        $threadModel = $eventModel->forumThreads()->visible()->where('id', $thread)->firstOrFail();

        $validated = $request->validate(['respondent_token' => ['required', 'string', 'max:64']]);

        $threadModel->votes()->where('respondent_token', $validated['respondent_token'])->delete();

        return response()->json(['votes_count' => $threadModel->votes()->count()]);
    }

    public function report(Request $request, string $subdomain, string $event, string $thread): JsonResponse
    {
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('slug', $event)->published()->firstOrFail();

        // The forum of a closed event is as revealing as its lineup: who is
        // asking what, under their own names. There is no registration in this
        // route to check against, so a private event has no public forum.
        if ($eventModel->isPrivate()) {
            abort(404);
        }
        $threadModel = $eventModel->forumThreads()->visible()->where('id', $thread)->firstOrFail();

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'reporter_token' => ['required', 'string', 'max:64'],
        ]);

        $exists = $threadModel->reports()->where('reporter_token', $validated['reporter_token'])->exists();
        if (! $exists) {
            $threadModel->reports()->create([
                'tenant_id' => $tenant->id,
                'reason' => $validated['reason'] ?? null,
                'reporter_token' => $validated['reporter_token'],
            ]);
        }

        return response()->json(['message' => "Thanks — we'll take a look."]);
    }

    private function getTenant()
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404);
        }

        return $tenant;
    }
}
