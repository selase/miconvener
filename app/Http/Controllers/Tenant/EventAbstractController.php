<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Libraries\Helper;
use App\Mail\Events\AbstractDecisionNotificationMail;
use App\Models\Event;
use App\Models\EventAbstract;
use App\Models\EventAbstractReview;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class EventAbstractController extends Controller
{
    public function index(Request $request, string $subdomain, Event $event): Response|JsonResponse
    {
        Gate::authorize('read abstract');

        $query = $event->abstracts()
            ->with(['authors', 'reviews.reviewer', 'decider', 'sessions']);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('track')) {
            $query->where('track', $request->query('track'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search): void {
                $q->where('title', 'ilike', "%{$search}%")
                    ->orWhere('code', 'ilike', "%{$search}%")
                    ->orWhereHas('authors', function ($aq) use ($search): void {
                        $aq->where('first_name', 'ilike', "%{$search}%")
                            ->orWhere('last_name', 'ilike', "%{$search}%")
                            ->orWhere('email', 'ilike', "%{$search}%");
                    });
            });
        }

        $abstracts = $query->get();

        $stats = [
            'total' => $event->abstracts()->count(),
            'submitted' => $event->abstracts()->where('status', EventAbstract::STATUS_SUBMITTED)->count(),
            'under_review' => $event->abstracts()->where('status', EventAbstract::STATUS_UNDER_REVIEW)->count(),
            'accepted_oral' => $event->abstracts()->where('status', EventAbstract::STATUS_ACCEPTED_ORAL)->count(),
            'accepted_poster' => $event->abstracts()->where('status', EventAbstract::STATUS_ACCEPTED_POSTER)->count(),
            'rejected' => $event->abstracts()->where('status', EventAbstract::STATUS_REJECTED)->count(),
        ];

        // Potential reviewers in the tenant organization
        $reviewers = User::query()
            ->where('tenant_id', $event->tenant_id)
            ->select(['id', 'first_name', 'last_name', 'email'])
            ->orderBy('first_name')
            ->get();

        $tracks = $event->abstracts()->whereNotNull('track')->distinct()->pluck('track')->values();

        $payload = [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'slug' => $event->slug,
            ],
            'abstracts' => $abstracts->map(fn (EventAbstract $a): array => [
                'id' => $a->id,
                'code' => $a->code,
                'title' => $a->title,
                'track' => $a->track,
                'presentation_preference' => $a->presentation_preference,
                'structured_abstract' => $a->structured_abstract,
                'body' => $a->body,
                'keywords' => $a->keywords,
                'status' => $a->status,
                'conflict_of_interest' => $a->conflict_of_interest,
                'decision_notes' => $a->decision_notes,
                'decided_at' => $a->decided_at?->toIso8601String(),
                'decided_by' => $a->decider ? $a->decider->name : null,
                'file_url' => $a->file_path ? Helper::storageUrl($a->file_path) : null,
                'created_at' => $a->created_at?->toIso8601String(),
                'average_score' => $a->averageScore(),
                'authors' => $a->authors->map(fn ($author) => [
                    'id' => $author->id,
                    'name' => $author->full_name,
                    'first_name' => $author->first_name,
                    'last_name' => $author->last_name,
                    'email' => $author->email,
                    'affiliation' => $author->affiliation,
                    'country' => $author->country,
                    'is_presenting' => $author->is_presenting,
                    'is_corresponding' => $author->is_corresponding,
                ]),
                'reviews' => $a->reviews->map(fn ($r) => [
                    'id' => $r->id,
                    'reviewer_id' => $r->reviewer_id,
                    'reviewer_name' => $r->reviewer?->name ?? 'Reviewer',
                    'status' => $r->status,
                    'novelty_score' => $r->novelty_score,
                    'methodology_score' => $r->methodology_score,
                    'relevance_score' => $r->relevance_score,
                    'clarity_score' => $r->clarity_score,
                    'total_score' => $r->total_score,
                    'recommendation' => $r->recommendation,
                    'comments_to_author' => $r->comments_to_author,
                    'confidential_comments' => $r->confidential_comments,
                    'completed_at' => $r->completed_at?->toIso8601String(),
                ]),
                'sessions' => $a->sessions->map(fn ($s) => [
                    'id' => $s->id,
                    'title' => $s->title,
                    'type' => $s->type,
                    'location' => $s->location,
                    'starts_at' => $s->starts_at->toIso8601String(),
                ]),
            ]),
            'stats' => $stats,
            'tracks' => $tracks,
            'reviewers' => $reviewers,
        ];

        if ($request->wantsJson()) {
            return response()->json($payload);
        }

        return Inertia::render('Tenant/Events/Abstracts', $payload);
    }

    public function show(Request $request, string $subdomain, Event $event, EventAbstract $abstract): JsonResponse
    {
        Gate::authorize('read abstract');

        $abstract->load(['authors', 'reviews.reviewer', 'decider', 'sessions']);

        return response()->json([
            'abstract' => $abstract,
            'average_score' => $abstract->averageScore(),
        ]);
    }

    public function assignReviewer(Request $request, string $subdomain, Event $event, EventAbstract $abstract): JsonResponse
    {
        Gate::authorize('assign abstract-reviewer');

        $validated = $request->validate([
            'reviewer_id' => ['required', 'exists:users,id'],
        ]);

        $review = EventAbstractReview::query()->updateOrCreate([
            'tenant_id' => $event->tenant_id,
            'abstract_id' => $abstract->id,
            'reviewer_id' => $validated['reviewer_id'],
        ], [
            'status' => EventAbstractReview::STATUS_PENDING,
        ]);

        if ($abstract->status === EventAbstract::STATUS_SUBMITTED) {
            $abstract->update(['status' => EventAbstract::STATUS_UNDER_REVIEW]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Reviewer assigned successfully.',
            'review' => $review->load('reviewer'),
        ]);
    }

    public function removeReviewer(Request $request, string $subdomain, Event $event, EventAbstract $abstract, EventAbstractReview $review): JsonResponse
    {
        Gate::authorize('assign abstract-reviewer');

        if ($review->abstract_id !== $abstract->id) {
            abort(404);
        }

        $review->delete();

        if ($abstract->reviews()->count() === 0 && $abstract->status === EventAbstract::STATUS_UNDER_REVIEW) {
            $abstract->update(['status' => EventAbstract::STATUS_SUBMITTED]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Reviewer assignment removed.',
        ]);
    }

    public function recordDecision(Request $request, string $subdomain, Event $event, EventAbstract $abstract): JsonResponse|RedirectResponse
    {
        Gate::authorize('decide abstract');

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.EventAbstract::STATUS_ACCEPTED_ORAL.','.EventAbstract::STATUS_ACCEPTED_POSTER.','.EventAbstract::STATUS_REJECTED],
            'decision_notes' => ['nullable', 'string'],
            'notify_author' => ['nullable', 'boolean'],
        ]);

        $abstract->update([
            'status' => $validated['status'],
            'decision_notes' => $validated['decision_notes'] ?? null,
            'decided_at' => now(),
            'decided_by' => $request->user()->id,
        ]);

        $notifyAuthor = (bool) ($validated['notify_author'] ?? true);
        if ($notifyAuthor) {
            $recipientEmail = $abstract->presentingAuthor?->email ?? $abstract->authors->first()?->email;
            if ($recipientEmail) {
                try {
                    Mail::to($recipientEmail)->send(new AbstractDecisionNotificationMail(
                        event: $event,
                        abstract: $abstract,
                        decisionNotes: $validated['decision_notes'] ?? null
                    ));
                } catch (Throwable $e) {
                    // Log mail transport error and continue
                    \Illuminate\Support\Facades\Log::warning("Failed to send abstract decision email: {$e->getMessage()}");
                }
            }
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Decision recorded: {$abstract->status}",
                'abstract' => $abstract->fresh(['authors', 'reviews', 'decider']),
            ]);
        }

        return back()->with('success', "Decision recorded successfully for abstract {$abstract->code}");
    }

    public function bulkDecision(Request $request, string $subdomain, Event $event): JsonResponse
    {
        Gate::authorize('decide abstract');

        $validated = $request->validate([
            'abstract_ids' => ['required', 'array', 'min:1'],
            'abstract_ids.*' => ['required', 'exists:event_abstracts,id'],
            'status' => ['required', 'string', 'in:'.EventAbstract::STATUS_ACCEPTED_ORAL.','.EventAbstract::STATUS_ACCEPTED_POSTER.','.EventAbstract::STATUS_REJECTED],
            'decision_notes' => ['nullable', 'string'],
            'notify_authors' => ['nullable', 'boolean'],
        ]);

        $abstracts = EventAbstract::query()
            ->where('event_id', $event->id)
            ->whereIn('id', $validated['abstract_ids'])
            ->with(['authors', 'presentingAuthor'])
            ->get();

        $notifyAuthors = (bool) ($validated['notify_authors'] ?? false);

        DB::connection('landlord')->transaction(function () use ($abstracts, $validated, $event, $notifyAuthors, $request): void {
            foreach ($abstracts as $abstract) {
                $abstract->update([
                    'status' => $validated['status'],
                    'decision_notes' => $validated['decision_notes'] ?? null,
                    'decided_at' => now(),
                    'decided_by' => $request->user()->id,
                ]);

                if ($notifyAuthors) {
                    $recipientEmail = $abstract->presentingAuthor?->email ?? $abstract->authors->first()?->email;
                    if ($recipientEmail) {
                        try {
                            Mail::to($recipientEmail)->send(new AbstractDecisionNotificationMail(
                                event: $event,
                                abstract: $abstract,
                                decisionNotes: $validated['decision_notes'] ?? null
                            ));
                        } catch (Throwable $e) {
                            \Illuminate\Support\Facades\Log::warning("Bulk abstract decision email failed: {$e->getMessage()}");
                        }
                    }
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Bulk decisions updated successfully for '.count($abstracts).' abstracts.',
        ]);
    }
}
