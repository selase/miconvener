<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventFormField;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class EventFormFieldController extends Controller
{
    public function index(string $subdomain, string $event): JsonResponse
    {
        $this->authorize('read event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        return response()->json([
            'registration_settings' => $eventModel->effectiveRegistrationSettings(),
            'form_fields' => $eventModel->formFields()->ordered()->get(),
        ]);
    }

    public function store(Request $request, string $subdomain, string $event): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $validated = $this->validateFormField($request, $eventModel);

        $maxSort = (int) $eventModel->formFields()->max('sort_order');

        $field = $eventModel->formFields()->create([
            ...$validated,
            'tenant_id' => $tenant->id,
            'sort_order' => $maxSort + 1,
        ]);

        return response()->json($field, 201);
    }

    public function update(Request $request, string $subdomain, string $event, string $field): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $formFieldModel = $eventModel->formFields()->where('id', $field)->firstOrFail();

        $validated = $this->validateFormField($request, $eventModel, $formFieldModel);

        $formFieldModel->update($validated);

        return response()->json($formFieldModel);
    }

    public function destroy(string $subdomain, string $event, string $field): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $formFieldModel = $eventModel->formFields()->where('id', $field)->firstOrFail();
        $formFieldModel->delete();

        return response()->json(['message' => 'Form field deleted successfully.']);
    }

    public function reorder(Request $request, string $subdomain, string $event): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['string', 'exists:landlord.event_form_fields,id'],
        ]);

        foreach ($validated['order'] as $index => $fieldId) {
            $eventModel->formFields()->where('id', $fieldId)->update(['sort_order' => $index]);
        }

        return response()->json(['message' => 'Fields reordered.']);
    }

    public function updateSettings(Request $request, string $subdomain, string $event): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $payload = $request->all();

        if (isset($payload['phone']) || isset($payload['dietary_requirements']) || isset($payload['accessibility_needs'])) {
            $phone = $payload['phone'] ?? 'optional';
            $dietary = $payload['dietary_requirements'] ?? 'optional';
            $accessibility = $payload['accessibility_needs'] ?? 'optional';

            $normalized = [
                'collect_phone' => $phone !== 'hidden',
                'require_phone' => $phone === 'required',
                'collect_dietary' => $dietary !== 'hidden',
                'require_dietary' => $dietary === 'required',
                'collect_accessibility' => $accessibility !== 'hidden',
                'require_accessibility' => $accessibility === 'required',
                'phone' => $phone,
                'dietary_requirements' => $dietary,
                'accessibility_needs' => $accessibility,
            ];
        } else {
            $validated = $request->validate([
                'collect_phone' => ['required', 'boolean'],
                'require_phone' => ['required', 'boolean'],
                'collect_dietary' => ['required', 'boolean'],
                'require_dietary' => ['required', 'boolean'],
                'collect_accessibility' => ['required', 'boolean'],
                'require_accessibility' => ['required', 'boolean'],
            ]);

            $normalized = [
                ...$validated,
                'phone' => ! $validated['collect_phone'] ? 'hidden' : ($validated['require_phone'] ? 'required' : 'optional'),
                'dietary_requirements' => ! $validated['collect_dietary'] ? 'hidden' : ($validated['require_dietary'] ? 'required' : 'optional'),
                'accessibility_needs' => ! $validated['collect_accessibility'] ? 'hidden' : ($validated['require_accessibility'] ? 'required' : 'optional'),
            ];
        }

        $eventModel->update(['registration_settings' => $normalized]);

        return response()->json([
            'message' => 'Registration settings updated.',
            'registration_settings' => $eventModel->effectiveRegistrationSettings(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateFormField(Request $request, Event $event, ?EventFormField $currentField = null): array
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'field_key' => [
                'nullable',
                'string',
                'max:64',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('landlord.event_form_fields', 'field_key')
                    ->where('event_id', $event->id)
                    ->ignore($currentField?->id),
            ],
            'field_type' => ['required', Rule::in(EventFormField::TYPES)],
            'help_text' => ['nullable', 'string', 'max:255'],
            'is_required' => ['required', 'boolean'],
            'options' => ['nullable', 'array'],
            'options.*.label' => ['required_with:options', 'string', 'max:255'],
            'options.*.value' => ['required_with:options', 'string', 'max:255'],
            'options.*.price' => ['nullable', 'integer', 'min:0'],
            'options.*.is_override' => ['nullable', 'boolean'],
            'conditional_logic' => ['nullable', 'array'],
            'conditional_logic.depends_on' => ['nullable', 'string'],
            'conditional_logic.depends_on_field' => ['nullable', 'string'],
            'conditional_logic.operator' => ['required_with:conditional_logic', Rule::in(['equals', 'not_equals', 'is_empty', 'is_not_empty'])],
            'conditional_logic.value' => ['nullable', 'string'],
        ]);

        if (! empty($validated['conditional_logic'])) {
            $depends = $validated['conditional_logic']['depends_on_field']
                ?? $validated['conditional_logic']['depends_on']
                ?? null;

            if (empty($depends)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'conditional_logic.depends_on_field' => ['The dependent field is required when conditional logic is set.'],
                ]);
            }

            $validated['conditional_logic']['depends_on_field'] = $depends;
            $validated['conditional_logic']['depends_on'] = $depends;
        }

        if (empty($validated['field_key'])) {
            $baseKey = Str::snake($validated['label']);
            $key = $baseKey;
            $counter = 1;
            while ($event->formFields()->where('field_key', $key)->when($currentField, fn ($q) => $q->where('id', '!=', $currentField->id))->exists()) {
                $key = $baseKey.'_'.$counter++;
            }
            $validated['field_key'] = $key;
        }

        return $validated;
    }

    private function findEvent(string $tenantId, string $eventId): Event
    {
        return Event::where('tenant_id', $tenantId)->where('id', $eventId)->firstOrFail();
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
