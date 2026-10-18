<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Community;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class LibraryItemRequest extends FormRequest
{
    final public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage community library');
    }

    /** @return array<string,mixed> */
    final public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenant()?->id;

        return ['title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'], 'plain_text' => ['nullable', 'string', 'max:50000'], 'state' => ['required', Rule::in(['draft', 'published'])], 'release_at' => ['nullable', 'date'], 'audience' => ['required', 'array:kind,group_ids'], 'audience.kind' => ['required', Rule::in(['all_members', 'groups'])], 'audience.group_ids' => ['required_if:audience.kind,groups', 'array', 'max:50'], 'audience.group_ids.*' => ['uuid', 'distinct', Rule::exists('community_groups', 'id')->where('tenant_id', $tenantId)->whereNull('archived_at')], 'edition' => ['nullable', 'string', 'max:120'], 'file' => ['nullable', 'file', 'mimes:pdf,docx,jpg,jpeg,png,webp', 'max:20480']];
    }

    /** @return array<string,string> */
    final public function messages(): array
    {
        return ['file.mimes' => 'Choose a PDF, DOCX, JPEG, PNG or WebP file.', 'file.max' => 'Files must be 20MB or smaller.', 'audience.group_ids.required_if' => 'Choose at least one active group.'];
    }
}
