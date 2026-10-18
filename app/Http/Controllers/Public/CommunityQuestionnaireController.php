<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendee\LibraryListRequest;
use App\Http\Requests\Attendee\SubmitCommunityQuestionnaireRequest;
use App\Models\CommunityQuestionnaire;
use App\Models\Tenant;
use App\Services\Community\CommunityContentAccess;
use App\Services\Community\CommunityQuestionnaireResults;
use App\Services\Community\CommunityQuestionnaires;
use App\Services\Community\MemberAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CommunityQuestionnaireController extends Controller
{
    public function index(LibraryListRequest $request, string $tenantSlug, CommunityQuestionnaires $engine, CommunityContentAccess $access): JsonResponse
    {
        $tenant = $this->tenant($tenantSlug);
        abort_unless($engine->available($tenant) && $access->readers($request->session(), $tenant, ['kind' => 'all_members'])->isNotEmpty(), 404);
        $forms = $engine->visible($request->session(), $tenant)->latest()->paginate(25);

        return response()->json(['organisation' => ['name' => $tenant->name, 'slug' => $tenant->slug], 'entries' => $forms->through(fn ($f) => ['id' => $f->id, 'title' => $f->title, 'description' => $f->description, 'kind' => $f->kind, 'state' => $f->state, 'accepting' => $engine->accepting($tenant, $f)])])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $tenantSlug, string $questionnaire, CommunityQuestionnaires $engine, CommunityQuestionnaireResults $results, MemberAccess $access): JsonResponse
    {
        $tenant = $this->tenant($tenantSlug);
        $form = $this->form($tenant, $questionnaire);
        $readers = $engine->readers($request->session(), $tenant, $form);
        abort_if($readers->isEmpty(), 404);
        $responded = $form->submissions()->where('tenant_id', $tenant->id)->whereIn('member_id', $readers->pluck('id'))->pluck('member_id')->all();

        return response()->json(['questionnaire' => $engine->payload($form), 'accepting' => $engine->accepting($tenant, $form),
            'contexts' => $readers->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'role' => $access->role($request->session(), $m), 'responded' => in_array($m->id, $responded, true)])->values(),
            'results' => $form->results_published_at === null ? null : $results->summary($form)])->header('Cache-Control', 'private, no-store');
    }

    public function submit(SubmitCommunityQuestionnaireRequest $request, string $tenantSlug, string $questionnaire, CommunityQuestionnaires $engine): JsonResponse
    {
        $tenant = $this->tenant($tenantSlug);
        $data = $request->validated();
        $submission = $engine->submit($tenant, $request->session(), $questionnaire, $data['member_id'], $data['answers'], $data['revision']);

        return response()->json(['received' => true, 'already_received' => ! $submission->wasRecentlyCreated])->header('Cache-Control', 'private, no-store');
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::query()->where('slug', $slug)->firstOrFail();
    }

    private function form(Tenant $tenant, string $id): CommunityQuestionnaire
    {
        return CommunityQuestionnaire::withoutGlobalScopes()->where('tenant_id',$tenant->id)->findOrFail($id);
    }
}
