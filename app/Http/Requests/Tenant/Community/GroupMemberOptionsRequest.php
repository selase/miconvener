<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Community;

use Illuminate\Foundation\Http\FormRequest;

final class GroupMemberOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['search' => ['nullable', 'string', 'max:100']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['search.max' => 'Search using 100 characters or fewer.'];
    }
}
