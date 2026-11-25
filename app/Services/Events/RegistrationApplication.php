<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\EventFormField;
use App\Models\EventRegistration;
use Illuminate\Support\Str;

/**
 * A registration's answers laid out the way the applicant saw the form: in
 * the form's sections, with choice labels rather than stored values, and
 * without the questions they left blank or never saw.
 */
final class RegistrationApplication
{
    /**
     * @return array{
     *     applicant: array<string, mixed>,
     *     sections: list<array{title: string|null, help: string|null, answers: list<array{label: string, value: string|null}>}>
     * }
     */
    public function describe(EventRegistration $registration): array
    {
        $answers = (array) ($registration->form_answers ?? []);
        $fields = EventFormField::query()
            ->where('event_id', $registration->event_id)
            ->where('tenant_id', $registration->tenant_id)
            ->ordered()
            ->get();

        $sections = [];
        $current = ['title' => null, 'help' => null, 'answers' => []];
        $shownKeys = [];

        foreach ($fields as $field) {
            if ($field->isHeading()) {
                $sections[] = $current;
                $current = ['title' => $field->label, 'help' => $field->help_text, 'answers' => []];

                continue;
            }

            $shownKeys[] = $field->field_key;

            $value = $this->display($field, $answers[$field->field_key] ?? null);

            // Hidden by an earlier answer, or an optional question left blank:
            // neither tells the reviewer anything.
            if (! $field->isConditionMet($answers) || $value === null) {
                continue;
            }

            $current['answers'][] = ['label' => $field->label, 'value' => $value];
        }
        $sections[] = $current;

        $orphans = array_diff_key($answers, array_flip($shownKeys));
        if ($orphans !== []) {
            $sections[] = [
                'title' => 'Answers to questions since removed',
                'help' => null,
                'answers' => array_map(
                    fn (string $key): array => ['label' => Str::headline($key), 'value' => $this->display(null, $answers[$key])],
                    array_keys($orphans),
                ),
            ];
        }

        return [
            'applicant' => [
                'id' => $registration->id,
                'full_name' => $registration->full_name,
                'email' => $registration->email,
                'phone' => $registration->phone,
                'status' => $registration->status,
                'ticket_type_name' => $registration->ticketType?->name,
                'dietary_requirements' => $registration->dietary_requirements,
                'accessibility_needs' => $registration->accessibility_needs,
                'approval_note' => $registration->approval_note,
                'submitted_at' => $registration->created_at?->toIso8601String(),
            ],
            'sections' => array_values(array_filter($sections, fn (array $section): bool => $section['answers'] !== [])),
        ];
    }

    private function display(?EventFormField $field, mixed $value): ?string
    {
        if (EventFormField::isBlankAnswer($value)) {
            return null;
        }

        if ($value === true) {
            return 'Yes';
        }

        $label = fn (mixed $item): string => $field ? $field->optionLabelFor((string) $item) : (string) $item;

        if (is_array($value)) {
            return implode(', ', array_map($label, $value));
        }

        return $label($value);
    }
}
