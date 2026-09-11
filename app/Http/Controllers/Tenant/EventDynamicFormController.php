<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventDynamicForm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class EventDynamicFormController extends Controller
{
    public function index(Request $request, string $subdomain, Event $event): JsonResponse
    {
        Gate::authorize('read dynamic-form');

        $forms = $event->dynamicForms()
            ->withCount('submissions')
            ->get()
            ->map(fn (EventDynamicForm $f): array => [
                'id' => $f->id,
                'title' => $f->title,
                'slug' => $f->slug,
                'description' => $f->description,
                'type' => $f->type,
                'is_active' => $f->is_active,
                'is_public' => $f->is_public,
                'requires_check_in' => $f->requires_check_in,
                'fields_count' => count($f->schema ?? []),
                'submissions_count' => $f->submissions_count,
                'public_url' => url("/e/{$event->slug}/forms/{$f->slug}"),
                'starts_at' => $f->starts_at?->toIso8601String(),
                'ends_at' => $f->ends_at?->toIso8601String(),
                'created_at' => $f->created_at?->toIso8601String(),
            ]);

        $summary = [
            'total_forms' => $forms->count(),
            'active_forms' => $forms->where('is_active', true)->count(),
            'total_submissions' => (int) $forms->sum('submissions_count'),
        ];

        return response()->json([
            'forms' => $forms,
            'summary' => $summary,
            'stats' => $summary,
        ]);
    }

    public function store(Request $request, string $subdomain, Event $event): JsonResponse
    {
        Gate::authorize('create dynamic-form');

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
            'is_public' => ['nullable', 'boolean'],
            'requires_check_in' => ['nullable', 'boolean'],
            'schema' => ['required', 'array'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'submission_limit' => ['nullable', 'integer', 'min:1'],
        ]);

        $baseSlug = Str::slug($validated['title']);
        $slug = $baseSlug;
        $counter = 1;

        while ($event->dynamicForms()->where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        $form = $event->dynamicForms()->create([
            'tenant_id' => $event->tenant_id,
            'title' => $validated['title'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'type' => $validated['type'],
            'is_active' => $validated['is_active'] ?? true,
            'is_public' => $validated['is_public'] ?? true,
            'requires_check_in' => $validated['requires_check_in'] ?? false,
            'schema' => $validated['schema'],
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
            'submission_limit' => $validated['submission_limit'] ?? null,
        ]);

        return response()->json([
            'message' => 'Dynamic form created successfully.',
            'form' => $form,
        ], 201);
    }

    public function show(Request $request, string $subdomain, Event $event, EventDynamicForm $form): JsonResponse
    {
        Gate::authorize('read dynamic-form');

        return response()->json([
            'form' => $form,
            'submissions_count' => $form->submissions()->count(),
            'public_url' => url("/e/{$event->slug}/forms/{$form->slug}"),
        ]);
    }

    public function update(Request $request, string $subdomain, Event $event, EventDynamicForm $form): JsonResponse
    {
        Gate::authorize('update dynamic-form');

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['sometimes', 'required', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
            'is_public' => ['nullable', 'boolean'],
            'requires_check_in' => ['nullable', 'boolean'],
            'schema' => ['sometimes', 'required', 'array'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'submission_limit' => ['nullable', 'integer', 'min:1'],
        ]);

        $form->update($validated);

        return response()->json([
            'message' => 'Dynamic form updated successfully.',
            'form' => $form,
        ]);
    }

    public function destroy(Request $request, string $subdomain, Event $event, EventDynamicForm $form): JsonResponse
    {
        Gate::authorize('delete dynamic-form');

        $form->delete();

        return response()->json([
            'message' => 'Dynamic form deleted successfully.',
        ]);
    }

    public function duplicate(Request $request, string $subdomain, Event $event, EventDynamicForm $form): JsonResponse
    {
        Gate::authorize('create dynamic-form');

        $title = "{$form->title} (Copy)";
        $baseSlug = Str::slug($title);
        $slug = $baseSlug;
        $counter = 1;

        while ($event->dynamicForms()->where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        $newForm = $event->dynamicForms()->create([
            'tenant_id' => $event->tenant_id,
            'title' => $title,
            'slug' => $slug,
            'description' => $form->description,
            'type' => $form->type,
            'is_active' => false,
            'is_public' => $form->is_public,
            'requires_check_in' => $form->requires_check_in,
            'schema' => $form->schema,
            'submission_limit' => $form->submission_limit,
        ]);

        return response()->json([
            'message' => 'Form duplicated successfully.',
            'form' => $newForm,
        ], 201);
    }

    public function submissions(Request $request, string $subdomain, Event $event, EventDynamicForm $form): JsonResponse
    {
        Gate::authorize('read dynamic-form');

        $submissions = $form->submissions()
            ->with(['registration:id,first_name,last_name,email,ticket_code'])
            ->latest('submitted_at')
            ->paginate(50);

        return response()->json([
            'form' => $form,
            'submissions' => $submissions,
        ]);
    }

    public function exportSubmissionsCsv(Request $request, string $subdomain, Event $event, EventDynamicForm $form): StreamedResponse
    {
        Gate::authorize('read dynamic-form');

        $submissions = $form->submissions()->with('registration')->get();
        $schema = $form->schema ?? [];
        $fieldKeys = array_map(fn ($f) => $f['key'] ?? '', $schema);
        $fieldLabels = array_map(fn ($f) => $f['label'] ?? ($f['key'] ?? ''), $schema);

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="form-'.$form->slug.'-submissions-'.date('Ymd-His').'.csv"',
        ];

        return response()->stream(function () use ($submissions, $fieldKeys, $fieldLabels): void {
            $handle = fopen('php://output', 'w');

            $csvHeaders = array_merge([
                'Submission ID',
                'Respondent Name',
                'Respondent Email',
                'Ticket Code',
                'Submitted At',
            ], $fieldLabels);

            fputcsv($handle, $csvHeaders);

            foreach ($submissions as $sub) {
                $answers = $sub->answers ?? [];
                $row = [
                    $sub->id,
                    $sub->respondent_name ?? $sub->registration?->full_name ?? '',
                    $sub->respondent_email ?? $sub->registration?->email ?? '',
                    $sub->registration?->ticket_code ?? '',
                    $sub->submitted_at?->format('Y-m-d H:i:s') ?? '',
                ];

                foreach ($fieldKeys as $key) {
                    $val = $answers[$key] ?? '';
                    if (is_array($val)) {
                        $val = implode(', ', $val);
                    }
                    $row[] = (string) $val;
                }

                fputcsv($handle, $row);
            }

            fclose($handle);
        }, 200, $headers);
    }
}
