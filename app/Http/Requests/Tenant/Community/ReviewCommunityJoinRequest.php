<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Community;

use Illuminate\Foundation\Http\FormRequest;

final class ReviewCommunityJoinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage members') ?? false;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['membership_type_id' => ['nullable', 'uuid'], 'create_distinct' => ['sometimes', 'boolean'], 'reason' => [$this->routeIs('tenant.community.joins.reject') || $this->boolean('create_distinct') ? 'required' : 'nullable', 'string', 'min:10', 'max:1000']];
    }

    public function messages(): array
    {
        return ['reason.min' => 'Explain the review in at least 10 characters.'];
    }
}
