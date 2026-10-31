<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Community\CommunitySmsReconciler;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class MessageOutcomeFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && Gate::forUser($this->user())->allows('access-superadmin-dashboard');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['source_kind' => ['sometimes', Rule::in(CommunitySmsReconciler::KINDS)], 'tenant_id' => ['nullable', 'uuid', 'exists:landlord.tenants,id']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['source_kind.in' => 'Choose one of the available message source types.'];
    }
}
