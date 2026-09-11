<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Services\Stratification\ParticipantStratificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PublicDynamicFormController extends Controller
{
    public function show(Request $request, string ...$params): Response
    {
        // Extract event slug and form slug from params (handles subdomain vs web route)
        $formSlug = end($params);
        $eventSlug = count($params) >= 2 ? $params[count($params) - 2] : null;

        $event = Event::where('slug', $eventSlug)->published()->firstOrFail();
        $form = $event->dynamicForms()->where('slug', $formSlug)->firstOrFail();

        $isOpen = $form->isOpen();
        $schema = $form->schema ?? [];

        return Inertia::render('Public/Forms/Show', [
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'slug' => $event->slug,
                'starts_at' => $event->starts_at?->format('F j, Y'),
                'ends_at' => $event->ends_at?->format('F j, Y'),
            ],
            'form' => [
                'id' => $form->id,
                'title' => $form->title,
                'slug' => $form->slug,
                'description' => $form->description,
                'type' => $form->type,
                'requires_check_in' => $form->requires_check_in,
                'schema' => $schema,
                'is_open' => $isOpen,
            ],
        ]);
    }

    public function submit(Request $request, string ...$params): JsonResponse
    {
        $formSlug = end($params);
        $eventSlug = count($params) >= 2 ? $params[count($params) - 2] : null;

        $event = Event::where('slug', $eventSlug)->published()->firstOrFail();
        $form = $event->dynamicForms()->where('slug', $formSlug)->firstOrFail();

        if (! $form->isOpen()) {
            return response()->json([
                'message' => 'This form is currently closed for submissions.',
            ], 422);
        }

        $validated = $request->validate([
            'respondent_name' => ['nullable', 'string', 'max:255'],
            'respondent_email' => ['nullable', 'email', 'max:255'],
            'ticket_code' => ['nullable', 'string', 'max:50'],
            'answers' => ['required', 'array'],
        ]);

        $schema = $form->schema ?? [];
        $answers = $validated['answers'];

        // Validate required fields defined in schema
        $errors = [];
        foreach ($schema as $field) {
            $key = $field['key'] ?? '';
            $label = $field['label'] ?? $key;
            $isRequired = ! empty($field['required']);

            if ($isRequired && (! isset($answers[$key]) || $answers[$key] === '' || $answers[$key] === [])) {
                $errors["answers.{$key}"] = ["{$label} is required."];
            }
        }

        if (! empty($errors)) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $errors,
            ], 422);
        }

        // Match registration if ticket_code or respondent_email is provided
        $registration = null;
        if (! empty($validated['ticket_code'])) {
            $registration = $event->registrations()->where('ticket_code', $validated['ticket_code'])->first();
        }

        if ($registration === null && ! empty($validated['respondent_email'])) {
            $registration = $event->registrations()->where('email', $validated['respondent_email'])->first();
        }

        if ($form->requires_check_in && ($registration === null || $registration->status !== EventRegistration::STATUS_CHECKED_IN)) {
            return response()->json([
                'message' => 'This evaluation is restricted to checked-in attendees of this conference.',
            ], 403);
        }

        $submission = $form->submissions()->create([
            'tenant_id' => $event->tenant_id,
            'event_id' => $event->id,
            'registration_id' => $registration?->id,
            'user_id' => null,
            'respondent_name' => $validated['respondent_name'] ?? ($registration ? mb_trim("{$registration->first_name} {$registration->last_name}") : null),
            'respondent_email' => $validated['respondent_email'] ?? $registration?->email,
            'answers' => $answers,
            'submitted_at' => now(),
        ]);

        // Auto-refresh dynamic stratification groups for this event
        $stratificationService = app(ParticipantStratificationService::class);
        $groups = $event->participantGroups()->where('type', 'dynamic')->get();
        foreach ($groups as $group) {
            $stratificationService->syncGroupMembers($group);
        }

        return response()->json([
            'message' => 'Form response submitted successfully. Thank you for your feedback!',
            'submission_id' => $submission->id,
        ], 201);
    }
}
