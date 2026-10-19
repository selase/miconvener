<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Where an Enterprise enquiry has got to, and what was said.
 */
final class UpdateLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('follow up signups');
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(Lead::STATUSES)],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['status.in' => 'Choose New, Contacted, Won or Lost.'];
    }
}
