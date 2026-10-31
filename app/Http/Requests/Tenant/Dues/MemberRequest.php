<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Dues;

use App\Models\DuesCharge;
use App\Services\Community\MemberIdentity;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Possible shared-contact matches require a fresh staff acknowledgement.
 * Exact emails cannot be duplicated through a distinct-person confirmation.
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
            'phone.required_without_all' => 'Add an email or a phone number so we can reach this member.',
            'phone.max' => 'The phone number may not be longer than 32 characters.',
            'membership_type_id.required' => 'Choose a dues class.',
            'membership_type_id.exists' => 'Choose an active dues class of this organisation.',
            'amount_override.integer' => 'The amount must be a whole number of minor units.',
            'amount_override.min' => 'The amount cannot be negative.',
            'joined_on.date_format' => 'Enter a valid join date in YYYY-MM-DD format.',
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
            'confirm_distinct' => ['sometimes', 'boolean'], 'duplicate_revision' => ['nullable', 'string', 'size:64'],
            'email' => [
                'bail',
                'nullable',
                'required_without_all:phone',
                'string',
                'email',
                'max:255',
                $this->uniqueIdentity($ignoreId),
            ],
            'phone' => [
                'bail',
                'nullable',
                'required_without_all:email',
                'string',
                'max:32',
                $this->uniqueIdentity($ignoreId),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (app(MemberIdentity::class)->normalisePhone($value) === null) {
                        $fail('Enter a valid phone number, such as 0241234567 or +233241234567.');
                    }
                },
            ],
            'membership_type_id' => ['required', Rule::exists('membership_types', 'id')->where('tenant_id', $tenantId)->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $currentClassId))],
            'amount_override' => ['nullable', 'integer', 'min:0', $this->zeroOrChargeable(...)],
            'joined_on' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $identity = app(MemberIdentity::class);
        $this->merge([
            'phone' => is_string($this->input('phone')) ? ($identity->normalisePhone($this->input('phone')) ?? $this->input('phone')) : $this->input('phone'),
            'email' => is_string($this->input('email')) ? $identity->normaliseEmail($this->input('email')) : $this->input('email'),
        ]);
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

    private function uniqueIdentity(?string $ignoreId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignoreId): void {
            $tenant = app(TenantContext::class)->getTenant();
            if ($tenant === null || ! is_string($this->input('name'))
                || (! is_string($this->input('phone')) && $this->input('phone') !== null)
                || (! is_string($this->input('email')) && $this->input('email') !== null)) {
                return;
            }
            $identity = app(MemberIdentity::class);
            if (! $identity->isDuplicate($tenant, $this->input('name'), $this->input('phone'), $this->input('email'), $ignoreId)) {
                return;
            }
            try {
                $identity->assertUnique($tenant, $this->only(['name', 'phone', 'email', 'confirm_distinct', 'duplicate_revision']), $ignoreId);
            } catch (\Illuminate\Validation\ValidationException $exception) {
                if (isset($exception->errors()[$attribute])) {
                    $fail($exception->errors()[$attribute][0]);
                }
            }
        };
    }
}
