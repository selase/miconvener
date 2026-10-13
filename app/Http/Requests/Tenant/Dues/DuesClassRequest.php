<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Dues;

use App\Enum\DuesPeriodEnum;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Shared rules for creating and editing a dues class. The app keeps no list of supported
 * currencies, so the code is only checked for shape.
 */
abstract class DuesClassRequest extends FormRequest
{
    final public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage members');
    }

    /**
     * @return array<string, string>
     */
    final public function messages(): array
    {
        return [
            'name.required' => 'Give the class a name.',
            'name.max' => 'The class name may not be longer than 120 characters.',
            'name.unique' => 'You already have a class with that name.',
            'amount.required' => 'Enter the amount members in this class pay each period.',
            'amount.integer' => 'The amount must be a whole number of minor units.',
            'amount.min' => 'The amount must be greater than zero.',
            'currency.required' => 'Choose a currency.',
            'currency.size' => 'The currency must be a three-letter code, such as GHS.',
            'is_active.required' => 'Say whether the class is active.',
            'is_active.boolean' => 'Active must be true or false.',
            'period.required' => 'Choose how often this class is billed.',
            'period.enum' => 'Choose monthly, quarterly or yearly.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function classRules(?string $ignoreId): array
    {
        return [
            'name' => ['required', 'string', 'max:120', $this->uniqueName($ignoreId)],
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'period' => ['required', Rule::enum(DuesPeriodEnum::class)],
        ];
    }

    /**
     * Currency codes are stored in upper case.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => mb_strtoupper($this->input('currency'))]);
        }
    }

    /**
     * Class names are unique within the organisation.
     */
    protected function uniqueName(?string $ignoreId = null): Unique
    {
        return Rule::unique('dues_classes', 'name')
            ->where('tenant_id', app(TenantContext::class)->getTenant()?->id)
            ->ignore($ignoreId);
    }
}
