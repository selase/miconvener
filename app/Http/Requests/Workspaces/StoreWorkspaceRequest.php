<?php

declare(strict_types=1);

namespace App\Http\Requests\Workspaces;

use App\Models\User;
use App\Services\Tenancy\CreateWorkspace;
use App\Support\TenantHandle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->hasVerifiedEmail() && in_array($user->status ?? null, [null, User::STATUS_ACTIVE], true);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $existing = is_string($this->input('request_key'))
            ? app(CreateWorkspace::class)->existing($this->user(), $this->input('request_key')) : null;
        $slugRules = array_values(array_filter(TenantHandle::rules(), fn ($rule): bool => ! is_string($rule) || ! str_starts_with($rule, 'unique:')));
        $slugRules[] = Rule::unique('landlord.tenants', 'slug')->ignore($existing?->id);

        return [
            'organization_name' => ['required', 'string', 'max:255'],
            'slug' => $slugRules,
            'request_key' => ['required', 'uuid'],
            'plan' => ['required', Rule::in(['free'])],
        ];
    }

    /** @return array{organization_name: string, slug: string, request_key: string, plan: string} */
    public function workspaceInput(): array
    {
        return [
            'organization_name' => $this->string('organization_name')->toString(),
            'slug' => $this->string('slug')->toString(),
            'request_key' => $this->string('request_key')->toString(),
            'plan' => $this->string('plan')->toString(),
        ];
    }

    public function messages(): array
    {
        return [...TenantHandle::messages(),
            'organization_name.required' => 'Enter your organisation name.',
            'organization_name.max' => 'Use an organisation name of 255 characters or fewer.',
            'request_key.required' => 'Refresh this page before creating your organisation.',
            'request_key.uuid' => 'Refresh this page before creating your organisation.',
            'plan.in' => 'New organisations start on Free. You can upgrade after creation.',
            'plan.required' => 'Choose the Free plan to get started.',
        ];
    }
}
