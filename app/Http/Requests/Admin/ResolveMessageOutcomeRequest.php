<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Community\CommunitySmsReconciler;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class ResolveMessageOutcomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && Gate::forUser($this->user())->allows('access-superadmin-dashboard');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return CommunitySmsReconciler::rules() + [
            'tenant_id' => ['required', 'uuid', 'exists:landlord.tenants,id'],
            'source_kind' => ['required', Rule::in(CommunitySmsReconciler::KINDS)], 'source_id' => ['required', 'uuid'], 'confirm' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['evidence.required' => 'Record the provider evidence supporting this outcome.', 'reason.min' => 'Explain the decision in at least 10 characters.', 'confirm.accepted' => 'Confirm this outcome is supported by provider evidence.'];
    }
}
