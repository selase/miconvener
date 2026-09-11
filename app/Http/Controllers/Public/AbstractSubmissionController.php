<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Libraries\Helper;
use App\Models\Event;
use App\Models\EventAbstract;
use App\Models\EventAbstractAuthor;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class AbstractSubmissionController extends Controller
{
    public function create(string $subdomain, string $event): Response
    {
        $tenant = $this->getTenant();

        $eventModel = Event::query()
            ->where('tenant_id', $tenant->id)
            ->where('slug', $event)
            ->published()
            ->firstOrFail();

        // Unique tracks extracted from sessions or predefined
        $tracks = $eventModel->sessions()->whereNotNull('track')->distinct()->pluck('track')->values();

        return Inertia::render('Public/Events/AbstractSubmit', [
            'event' => [
                'id' => $eventModel->id,
                'title' => $eventModel->title,
                'slug' => $eventModel->slug,
                'description' => $eventModel->description,
                'starts_at' => $eventModel->starts_at->toIso8601String(),
                'ends_at' => $eventModel->ends_at->toIso8601String(),
                'tracks' => $tracks,
            ],
            'org' => ['name' => $tenant->name],
        ]);
    }

    public function store(Request $request, string $subdomain, string $event): JsonResponse|RedirectResponse
    {
        $tenant = $this->getTenant();

        $eventModel = Event::query()
            ->where('tenant_id', $tenant->id)
            ->where('slug', $event)
            ->published()
            ->firstOrFail();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'track' => ['nullable', 'string', 'max:100'],
            'presentation_preference' => ['required', 'string', 'in:oral,poster,either'],
            'structured_abstract' => ['nullable', 'array'],
            'structured_abstract.background' => ['nullable', 'string'],
            'structured_abstract.methods' => ['nullable', 'string'],
            'structured_abstract.results' => ['nullable', 'string'],
            'structured_abstract.conclusion' => ['nullable', 'string'],
            'body' => ['nullable', 'string'],
            'keywords' => ['nullable', 'array'],
            'keywords.*' => ['string', 'max:50'],
            'conflict_of_interest' => ['nullable', 'string'],
            'file' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:20480'], // max 20MB
            'authors' => ['required', 'array', 'min:1'],
            'authors.*.first_name' => ['required', 'string', 'max:100'],
            'authors.*.last_name' => ['required', 'string', 'max:100'],
            'authors.*.email' => ['required', 'email', 'max:150'],
            'authors.*.affiliation' => ['required', 'string', 'max:255'],
            'authors.*.country' => ['nullable', 'string', 'max:100'],
            'authors.*.is_presenting' => ['nullable', 'boolean'],
            'authors.*.is_corresponding' => ['nullable', 'boolean'],
        ]);

        $filePath = null;
        if ($request->hasFile('file')) {
            $filePath = Helper::processUploadedFile(
                $request,
                'file',
                'abstract_manuscript',
                'event-abstracts',
                config('app.env') === 'production' ? 's3' : 'public'
            );
        }

        $abstract = DB::connection('landlord')->transaction(function () use ($tenant, $eventModel, $validated, $filePath, $request): EventAbstract {
            $code = EventAbstract::generateCode();

            $eventAbstract = EventAbstract::query()->create([
                'tenant_id' => $tenant->id,
                'event_id' => $eventModel->id,
                'user_id' => $request->user()?->id,
                'code' => $code,
                'title' => $validated['title'],
                'track' => $validated['track'] ?? null,
                'presentation_preference' => $validated['presentation_preference'],
                'structured_abstract' => $validated['structured_abstract'] ?? null,
                'body' => $validated['body'] ?? null,
                'keywords' => $validated['keywords'] ?? null,
                'conflict_of_interest' => $validated['conflict_of_interest'] ?? null,
                'file_path' => $filePath,
                'status' => EventAbstract::STATUS_SUBMITTED,
            ]);

            foreach ($validated['authors'] as $index => $authorData) {
                EventAbstractAuthor::query()->create([
                    'abstract_id' => $eventAbstract->id,
                    'first_name' => $authorData['first_name'],
                    'last_name' => $authorData['last_name'],
                    'email' => $authorData['email'],
                    'affiliation' => $authorData['affiliation'],
                    'country' => $authorData['country'] ?? null,
                    'is_presenting' => (bool) ($authorData['is_presenting'] ?? ($index === 0)),
                    'is_corresponding' => (bool) ($authorData['is_corresponding'] ?? ($index === 0)),
                    'sort_order' => $index,
                ]);
            }

            return $eventAbstract;
        });

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'code' => $abstract->code,
                'message' => 'Abstract submitted successfully.',
                'tracking_url' => route('public.events.abstracts.show', ['subdomain' => $subdomain, 'event' => $event, 'code' => $abstract->code]),
            ], 201);
        }

        return redirect()->route('public.events.abstracts.show', [
            'subdomain' => $subdomain,
            'event' => $event,
            'code' => $abstract->code,
        ])->with('success', "Your abstract has been submitted successfully with tracking code: {$abstract->code}");
    }

    public function show(string $subdomain, string $event, string $code): Response|JsonResponse
    {
        $tenant = $this->getTenant();

        $eventModel = Event::query()
            ->where('tenant_id', $tenant->id)
            ->where('slug', $event)
            ->published()
            ->firstOrFail();

        $abstract = EventAbstract::query()
            ->where('tenant_id', $tenant->id)
            ->where('event_id', $eventModel->id)
            ->where('code', $code)
            ->with(['authors', 'sessions'])
            ->firstOrFail();

        $payload = [
            'event' => [
                'id' => $eventModel->id,
                'title' => $eventModel->title,
                'slug' => $eventModel->slug,
            ],
            'abstract' => [
                'id' => $abstract->id,
                'code' => $abstract->code,
                'title' => $abstract->title,
                'track' => $abstract->track,
                'presentation_preference' => $abstract->presentation_preference,
                'structured_abstract' => $abstract->structured_abstract,
                'body' => $abstract->body,
                'keywords' => $abstract->keywords,
                'conflict_of_interest' => $abstract->conflict_of_interest,
                'status' => $abstract->status,
                'decision_notes' => $abstract->isDecided() ? $abstract->decision_notes : null,
                'decided_at' => $abstract->decided_at?->toIso8601String(),
                'created_at' => $abstract->created_at?->toIso8601String(),
                'file_url' => $abstract->file_path ? Helper::storageUrl($abstract->file_path) : null,
                'authors' => $abstract->authors->map(fn ($author) => [
                    'name' => $author->full_name,
                    'affiliation' => $author->affiliation,
                    'country' => $author->country,
                    'is_presenting' => $author->is_presenting,
                ]),
                'scheduled_sessions' => $abstract->sessions->map(fn ($session) => [
                    'id' => $session->id,
                    'title' => $session->title,
                    'type' => $session->type,
                    'location' => $session->location,
                    'starts_at' => $session->starts_at->toIso8601String(),
                    'ends_at' => $session->ends_at->toIso8601String(),
                ]),
            ],
            'org' => ['name' => $tenant->name],
        ];

        if (request()->wantsJson()) {
            return response()->json($payload);
        }

        return Inertia::render('Public/Events/AbstractTracking', $payload);
    }

    private function getTenant(): \App\Models\Tenant
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404);
        }

        return $tenant;
    }
}
