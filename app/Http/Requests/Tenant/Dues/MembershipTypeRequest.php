<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Dues;

use App\Enum\DuesPeriodEnum;
use App\Models\DuesCharge;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Shared rules for creating and editing a dues class. The app keeps no list of supported
 * currencies, so the code is only checked for shape.
 */
abstract class MembershipTypeRequest extends FormRequest
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
            'amount.required_with' => 'Enter the amount members of this type pay each period, or clear the period for a type without dues.',
            'amount.integer' => 'The amount must be a whole number of minor units.',
            'amount.min' => 'The amount must be at least '.DuesCharge::MINIMUM_AMOUNT.' minor units ('.number_format(DuesCharge::MINIMUM_AMOUNT / 100, 2).').',
            'currency.required' => 'Choose a currency.',
            'currency.size' => 'The currency must be a three-letter code, such as GHS.',
            'is_active.required' => 'Say whether the class is active.',
            'is_active.boolean' => 'Active must be true or false.',
            'period.required_with' => 'Choose how often this type is billed, or clear the amount for a type without dues.',
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
            'amount' => ['nullable', 'required_with:period', 'integer', 'min:'.DuesCharge::MINIMUM_AMOUNT],
            'currency' => ['required', 'string', 'size:3'],
            'period' => ['nullable', 'required_with:amount', Rule::enum(DuesPeriodEnum::class)],
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
        return Rule::unique('membership_types', 'name')
            ->where('tenant_id', app(TenantContext::class)->getTenant()?->id)
            ->ignore($ignoreId);
    }
}
