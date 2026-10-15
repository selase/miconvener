<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateCommercialTermsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('access-superadmin-dashboard');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'stream' => ['required', 'in:dues,giving,contributions'],
            'rate' => ['present', 'nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'cap_amount' => ['present', 'nullable', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'stream.in' => 'Choose dues, giving or contributions. Ticket terms use the existing ticket settings.',
            'rate.min' => 'Enter a percentage from 0 to 100; zero grants a waiver.',
            'rate.max' => 'The commission percentage cannot exceed 100.',
            'rate.decimal' => 'Use at most two decimal places.',
            'cap_amount.min' => 'Enter a non-negative cap in minor units, or leave it blank for no cap.',
            'reason.required' => 'Record a reason for this commercial terms change.',
        ];
    }
}
