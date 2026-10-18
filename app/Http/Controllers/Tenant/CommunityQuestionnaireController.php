<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendee\LibraryListRequest;
use App\Http\Requests\Tenant\Community\QuestionnaireStateRequest;
use App\Http\Requests\Tenant\Community\SaveQuestionnaireRequest;
use App\Models\CommunityGroup;
use App\Models\CommunityQuestionnaire;
use App\Models\CommunitySubmission;
use App\Models\Tenant;
use App\Services\Community\CommunityQuestionnaireResults;
use App\Services\Community\CommunityQuestionnaires;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CommunityQuestionnaireController extends Controller
{
    public function index(CommunityQuestionnaires $engine): Response
    {
        abort_unless(auth()->user()?->can('manage community polls') || auth()->user()?->can('read community poll results'), 403);
        $tenant = $this->tenant();

        return Inertia::render('Tenant/Community/Polls', ['forms' => CommunityQuestionnaire::query()->where('tenant_id', $tenant->id)->latest()->paginate(25)->through(fn ($form) => $engine->payload($form, true)),
            'groups' => CommunityGroup::query()->where('tenant_id', $tenant->id)->whereNull('archived_at')->orderBy('name')->get(['id', 'name']), 'canPublish' => $engine->canPublish($tenant)]);
    }

    public function store(SaveQuestionnaireRequest $request, CommunityQuestionnaires $engine): JsonResponse
    {
        return response()->json(['questionnaire' => $engine->payload($engine->save($this->tenant(), $request->validated(), null, (int) $request->user()->id), true)], 201);
    }

    public function update(SaveQuestionnaireRequest $request, string $subdomain, string $questionnaire, CommunityQuestionnaires $engine): JsonResponse
    {
        return response()->json(['questionnaire' => $engine->payload($engine->save($this->tenant(), $request->validated(), $questionnaire, (int) $request->user()->id), true)]);
    }

    public function state(QuestionnaireStateRequest $request, string $subdomain, string $questionnaire, CommunityQuestionnaires $engine): JsonResponse
    {
        $form = DB::connection('landlord')->transaction(function () use ($questionnaire, $request, $engine): CommunityQuestionnaire {
            $form = CommunityQuestionnaire::withoutGlobalScopes()->where('tenant_id', $this->tenant()->id)->lockForUpdate()->findOrFail($questionnaire);
            if ($request->filled('state')) {
                $state = $request->validated('state');
                if ($state === 'open') {
                    abort_unless($engine->canPublish($this->tenant()) && $form->questions()->exists(), 403, 'Publishing requires an eligible plan and at least one question.');
                }
                $form->state = $state;
            }
            if ($request->has('results_published')) {
                $form->results_published_at = $request->boolean('results_published') ? now() : null;
            }
            $form->save();

            return $form;
        });

        return response()->json(['questionnaire' => $engine->payload($form, true)]);
    }

    public function results(LibraryListRequest $request, string $subdomain, string $questionnaire, CommunityQuestionnaireResults $results): JsonResponse
    {
        $this->authorize('read community poll results');
        $form = $this->form($questionnaire);
        $responses = $form->submissions()->where('tenant_id', $form->tenant_id)->with(['member', 'answers'])->latest()->paginate(25);

        return response()->json(['summary' => $results->summary($form), 'responses' => $responses->through($this->submissionPayload(...))])->header('Cache-Control', 'private, no-store');
    }

    public function export(string $subdomain, string $questionnaire, CommunityQuestionnaireResults $results): StreamedResponse
    {
        $this->authorize('read community poll results');

        return $results->export($this->form($questionnaire));
    }

    /** @return array<string,mixed> */
    private function submissionPayload(CommunitySubmission $submission): array
    {
        return ['member' => ['id' => $submission->member_id, 'name' => $submission->member->name, 'email' => $submission->member->email],
            'created_at' => $submission->created_at->toIso8601String(),
            'answers' => $submission->answers->map(fn ($answer) => ['question_id' => $answer->question_id, 'option_id' => $answer->option_id, 'response_text' => $answer->response_text, 'response_number' => $answer->response_number, 'response_payload' => $answer->response_payload])->values()->all()];
    }

    private function tenant(): Tenant
    {
        return app(TenantContext::class)->getTenant() ?? abort(404);
    }

    private function form(string $id): CommunityQuestionnaire
    {
        return CommunityQuestionnaire::withoutGlobalScopes()->where('tenant_id', $this->tenant()->id)->findOrFail($id);
    }
}
