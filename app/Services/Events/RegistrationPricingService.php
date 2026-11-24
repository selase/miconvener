<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventFormField;
use App\Models\EventTicketType;
use Illuminate\Validation\ValidationException;

final class RegistrationPricingService
{
    /**
     * Validate form answers against event form field rules and conditional dependencies.
     *
     * @param  array<string, mixed>  $rawAnswers
     * @return array<string, mixed> Cleaned, active answers
     *
     * @throws ValidationException
     */
    public function validateAndCleanAnswers(Event $event, array $rawAnswers): array
    {
        $fields = $event->formFields()->ordered()->get();
        $cleanedAnswers = [];
        $errors = [];

        foreach ($fields as $field) {
            if ($field->isHeading()) {
                continue;
            }

            $key = $field->field_key;
            $value = $rawAnswers[$key] ?? null;

            // Check conditional visibility against already validated answers
            if (! $field->isConditionMet($rawAnswers)) {
                // Inactive field -- strip any stale answer
                continue;
            }

            if ($field->isTextType() && is_string($value)) {
                $value = mb_trim($value);
            }

            // An unticked box arrives as false; treat it as unanswered.
            if ($field->field_type === EventFormField::TYPE_CHECKBOX && ! filter_var($value, FILTER_VALIDATE_BOOL)) {
                $value = null;
            }

            if (is_null($value) || $value === '' || (is_array($value) && empty($value))) {
                if ($field->is_required) {
                    $errors['form_answers.'.$key] = ["The {$field->label} field is required."];
                }

                continue;
            }

            $error = $this->answerError($field, $value);
            if ($error !== null) {
                $errors['form_answers.'.$key] = [$error];

                continue;
            }

            $cleanedAnswers[$key] = $field->field_type === EventFormField::TYPE_CHECKBOX ? true : $value;
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        return $cleanedAnswers;
    }

    /**
     * Compute authoritative registration price from base ticket/event price and active form choice pricing.
     *
     * @param  array<string, mixed>  $cleanedAnswers
     * @return array{amount: int, is_free: bool, currency: string, breakdown: array<string, mixed>}
     */
    public function calculatePrice(Event $event, ?EventTicketType $ticketType, array $cleanedAnswers, ?int $memberBasePrice = null): array
    {
        $basePrice = $memberBasePrice ?? ($ticketType ? $ticketType->price : (int) ($event->ticket_price ?? 0));
        $fields = $event->formFields()->ordered()->get();

        $priceModifierTotal = 0;
        $overridePrice = null;
        $breakdownItems = [];

        foreach ($fields as $field) {
            $key = $field->field_key;
            if (! isset($cleanedAnswers[$key]) || ! $field->isConditionMet($cleanedAnswers)) {
                continue;
            }

            $selectedVal = (string) $cleanedAnswers[$key];
            if (empty($field->options) || ! is_array($field->options)) {
                continue;
            }

            foreach ($field->options as $opt) {
                if ((string) ($opt['value'] ?? '') === $selectedVal && isset($opt['price']) && is_numeric($opt['price'])) {
                    $optPrice = (int) $opt['price'];

                    if (! empty($opt['is_override'])) {
                        $overridePrice = $optPrice;
                        $breakdownItems[] = [
                            'field' => $field->label,
                            'option' => $opt['label'] ?? $selectedVal,
                            'type' => 'override',
                            'amount' => $optPrice,
                        ];
                    } else {
                        $priceModifierTotal += $optPrice;
                        $breakdownItems[] = [
                            'field' => $field->label,
                            'option' => $opt['label'] ?? $selectedVal,
                            'type' => 'add',
                            'amount' => $optPrice,
                        ];
                    }
                }
            }
        }

        $finalAmount = ! is_null($overridePrice)
            ? $overridePrice + $priceModifierTotal
            : $basePrice + $priceModifierTotal;

        $finalAmount = max(0, $finalAmount);

        return [
            'amount' => $finalAmount,
            'is_free' => $finalAmount === 0,
            'currency' => $event->currency ?: 'GHS',
            'breakdown' => [
                'base_price' => $basePrice,
                'items' => $breakdownItems,
                'total' => $finalAmount,
            ],
        ];
    }

    /**
     * The reason a non-empty answer is unacceptable for its field, or null.
     */
    private function answerError(EventFormField $field, mixed $value): ?string
    {
        if (in_array($field->field_type, EventFormField::CHOICE_TYPES, true)) {
            $allowedValues = array_map(fn ($opt): string => (string) ($opt['value'] ?? ''), (array) $field->options);
            $chosen = is_array($value) ? $value : [$value];

            foreach ($chosen as $item) {
                if (! is_scalar($item) || ! in_array((string) $item, $allowedValues, true)) {
                    return "The selected {$field->label} is invalid.";
                }
            }

            return null;
        }

        if ($field->field_type === EventFormField::TYPE_NUMBER) {
            return is_numeric($value) ? null : "The {$field->label} must be a number.";
        }

        if (! $field->isTextType()) {
            return null;
        }

        if (! is_string($value)) {
            return "The {$field->label} must be text.";
        }

        if ($field->field_type === EventFormField::TYPE_EMAIL && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return "The {$field->label} must be a valid email address.";
        }

        $length = $field->measure($value);
        $unit = $field->countsWords() ? 'words' : 'characters';

        if ($field->min_length !== null && $length < $field->min_length) {
            return "The {$field->label} must be at least {$field->min_length} {$unit}.";
        }

        if ($field->countsWords() && $field->max_length !== null && $length > $field->max_length) {
            return "The {$field->label} must not be longer than {$field->max_length} words.";
        }

        $characterLimit = $field->characterLimit();
        if ($characterLimit !== null && mb_strlen($value) > $characterLimit) {
            return "The {$field->label} must not be longer than {$characterLimit} characters.";
        }

        return null;
    }
}
