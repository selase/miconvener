<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Libraries\Helper;
use App\Models\Event;
use App\Models\EventAbstract;
use App\Models\EventAbstractReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class AbstractReviewController extends Controller
{
    public function index(Request $request, string $subdomain, Event $event): JsonResponse
    {
        Gate::authorize('review abstract');

        $user = $request->user();

        $reviews = EventAbstractReview::query()
            ->where('tenant_id', $event->tenant_id)
            ->where('reviewer_id', $user->id)
            ->whereHas('abstract', fn ($q) => $q->where('event_id', $event->id))
            ->with(['abstract'])
            ->get();

        return response()->json([
            'reviews' => $reviews->map(fn (EventAbstractReview $r): array => [
                'id' => $r->id,
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
                'abstract' => [
                    'id' => $r->abstract->id,
                    'code' => $r->abstract->code,
                    'title' => $r->abstract->title,
                    'track' => $r->abstract->track,
                    'presentation_preference' => $r->abstract->presentation_preference,
                    'structured_abstract' => $r->abstract->structured_abstract,
                    'body' => $r->abstract->body,
                    'keywords' => $r->abstract->keywords,
                    'conflict_of_interest' => $r->abstract->conflict_of_interest,
                    'file_url' => $r->abstract->file_path ? Helper::storageUrl($r->abstract->file_path) : null,
                ],
            ]),
        ]);
    }

    public function submit(Request $request, string $subdomain, Event $event, EventAbstract $abstract): JsonResponse
    {
        Gate::authorize('review abstract');

        $user = $request->user();

        $review = EventAbstractReview::query()
            ->where('abstract_id', $abstract->id)
            ->where('reviewer_id', $user->id)
            ->firstOrFail();

        $validated = $request->validate([
            'novelty_score' => ['required', 'integer', 'min:1', 'max:5'],
            'methodology_score' => ['required', 'integer', 'min:1', 'max:5'],
            'relevance_score' => ['required', 'integer', 'min:1', 'max:5'],
            'clarity_score' => ['required', 'integer', 'min:1', 'max:5'],
            'recommendation' => ['required', 'string', 'in:'.EventAbstractReview::RECOMMENDATION_ORAL.','.EventAbstractReview::RECOMMENDATION_POSTER.','.EventAbstractReview::RECOMMENDATION_REJECT],
            'comments_to_author' => ['nullable', 'string', 'max:5000'],
            'confidential_comments' => ['nullable', 'string', 'max:5000'],
        ]);

        $totalScore = round((
            $validated['novelty_score'] +
            $validated['methodology_score'] +
            $validated['relevance_score'] +
            $validated['clarity_score']
        ) / 4.0, 2);

        $review->update([
            'status' => EventAbstractReview::STATUS_COMPLETED,
            'novelty_score' => $validated['novelty_score'],
            'methodology_score' => $validated['methodology_score'],
            'relevance_score' => $validated['relevance_score'],
            'clarity_score' => $validated['clarity_score'],
            'total_score' => $totalScore,
            'recommendation' => $validated['recommendation'],
            'comments_to_author' => $validated['comments_to_author'] ?? null,
            'confidential_comments' => $validated['confidential_comments'] ?? null,
            'completed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Review submitted successfully.',
            'review' => $review->fresh(),
            'abstract_average_score' => $abstract->fresh()->averageScore(),
        ]);
    }
}
