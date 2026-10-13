<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Dues;

use App\Models\DuesCharge;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * A new member. A member is identified in the organisation by phone, or by
 * email when they have no phone; only phone is unique in the database, so the
 * email rule lives here: an email may not repeat among members without a phone.
 */
abstract class MemberRequest extends FormRequest
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
            'name.required' => 'Enter the member\'s name.',
            'name.max' => 'The name may not be longer than 160 characters.',
            'email.required_without_all' => 'Add an email or a phone number so we can reach this member.',
            'email.email' => 'Enter a valid email address.',
            'email.unique' => 'A member with that email already exists.',
            'phone.required_without_all' => 'Add an email or a phone number so we can reach this member.',
            'phone.max' => 'The phone number may not be longer than 32 characters.',
            'phone.unique' => 'A member with that phone number already exists.',
            'dues_class_id.required' => 'Choose a dues class.',
            'dues_class_id.exists' => 'Choose an active dues class of this organisation.',
            'amount_override.integer' => 'The amount must be a whole number of minor units.',
            'amount_override.min' => 'The amount cannot be negative.',
            'joined_on.date' => 'Enter a valid join date.',
            'notes.max' => 'Notes may not be longer than 1000 characters.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function memberRules(?string $ignoreId, ?string $currentClassId = null): array
    {
        $tenantId = app(TenantContext::class)->getTenant()?->id;

        return [
            'name' => ['required', 'string', 'max:160'],
            'email' => [
                'nullable',
                'required_without_all:phone',
                'email',
                'max:255',
                Rule::unique('members', 'email')->where('tenant_id', $tenantId)->whereNull('phone')->ignore($ignoreId),
            ],
            'phone' => [
                'nullable',
                'required_without_all:email',
                'string',
                'max:32',
                $this->uniquePhone($tenantId, $ignoreId),
            ],
            'dues_class_id' => ['required', Rule::exists('dues_classes', 'id')->where('tenant_id', $tenantId)->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $currentClassId))],
            'amount_override' => ['nullable', 'integer', 'min:0', $this->zeroOrChargeable(...)],
            'joined_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * An override of 0 waives the member's dues; any other amount must be one
     * the gateway can collect.
     *
     * @param  Closure(string): mixed  $fail
     */
    private function zeroOrChargeable(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_numeric($value) && (int) $value > 0 && (int) $value < DuesCharge::MINIMUM_AMOUNT) {
            $fail('The amount must be 0 (waived) or at least '.DuesCharge::MINIMUM_AMOUNT.' minor units ('.number_format(DuesCharge::MINIMUM_AMOUNT / 100, 2).').');
        }
    }

    private function uniquePhone(?string $tenantId, ?string $ignoreId): Unique
    {
        return Rule::unique('members', 'phone')->where('tenant_id', $tenantId)->ignore($ignoreId);
    }
}
