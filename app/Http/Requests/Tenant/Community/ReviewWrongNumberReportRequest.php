<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Community;

use Illuminate\Foundation\Http\FormRequest;

final class ReviewWrongNumberReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage members') ?? false;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['method' => [$this->routeIs('tenant.community.reports.resolve') ? 'required' : 'nullable', \Illuminate\Validation\Rule::in(['in_person', 'known_contact'])], 'confirmed' => [$this->routeIs('tenant.community.reports.review') ? 'required' : 'nullable', 'boolean'], 'reason' => ['required', 'string', 'min:10', 'max:1000']];
    }

    public function messages(): array
    {
        return ['reason.min' => 'Record the independent review in at least 10 characters.'];
    }
}
