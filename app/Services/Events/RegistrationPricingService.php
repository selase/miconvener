<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
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
            $key = $field->field_key;
            $value = $rawAnswers[$key] ?? null;

            // Check conditional visibility against already validated answers
            if (! $field->isConditionMet($rawAnswers)) {
                // Inactive field -- strip any stale answer
                continue;
            }

            if ($field->is_required && (is_null($value) || $value === '' || (is_array($value) && empty($value)))) {
                $errors['form_answers.'.$key] = ["The {$field->label} field is required."];

                continue;
            }

            if (! is_null($value) && $value !== '') {
                // If field has predefined options, validate that value is among allowed options
                if (! empty($field->options) && is_array($field->options)) {
                    $allowedValues = array_map(fn ($opt) => (string) ($opt['value'] ?? ''), $field->options);

                    if (is_array($value)) {
                        foreach ($value as $valItem) {
                            if (! in_array((string) $valItem, $allowedValues, true)) {
                                $errors['form_answers.'.$key] = ["The selected {$field->label} is invalid."];
                                break;
                            }
                        }
                    } elseif (! in_array((string) $value, $allowedValues, true)) {
                        $errors['form_answers.'.$key] = ["The selected {$field->label} is invalid."];

                        continue;
                    }
                }

                $cleanedAnswers[$key] = $value;
            }
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
    public function calculatePrice(Event $event, ?EventTicketType $ticketType, array $cleanedAnswers): array
    {
        $basePrice = $ticketType ? $ticketType->price : (int) ($event->ticket_price ?? 0);
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
}
