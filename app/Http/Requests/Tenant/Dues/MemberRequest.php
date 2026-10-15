<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Dues;

use App\Models\DuesCharge;
use App\Models\Member;
use App\Services\Tenancy\TenantContext;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Members may share a phone; name plus phone guards against duplicates.
 * An email may not repeat among members without a phone.
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
            'membership_type_id.required' => 'Choose a dues class.',
            'membership_type_id.exists' => 'Choose an active dues class of this organisation.',
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
            'membership_type_id' => ['required', Rule::exists('membership_types', 'id')->where('tenant_id', $tenantId)->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $currentClassId))],
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

    private function uniquePhone(?string $tenantId, ?string $ignoreId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($tenantId, $ignoreId): void {
            if (! is_string($value) || ! is_string($this->input('name'))) {
                return;
            }

            $phone = PhoneNumber::toInternationalDigits($value) ?? mb_trim($value);
            $name = Str::lower(Str::squish($this->input('name')));

            $duplicate = Member::query()
                ->where('tenant_id', $tenantId)
                ->whereNotNull('phone')
                ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
                ->select(['id', 'name', 'phone'])
                ->cursor()
                ->contains(fn (Member $member): bool => (PhoneNumber::toInternationalDigits($member->phone) ?? mb_trim($member->phone)) === $phone
                    && Str::lower(Str::squish($member->name)) === $name);

            if ($duplicate) {
                $fail('This person is already a member.');
            }
        };
    }
}
